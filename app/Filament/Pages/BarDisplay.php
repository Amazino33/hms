<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\InventoryService;
use App\Services\PermissionService;
use App\Services\ReturnConfirmationService;
use App\Services\FridgeStockEstimateService;
use App\Models\GuestDeliveryRefusal;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestShifts;
use App\Services\Guest\RoomDeliveryService;
use App\Services\UserFeedback;
use App\Models\Product;
use App\Models\WareHouse;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class BarDisplay extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Bar Display';
    protected string $view = 'filament.pages.bar-display';

    // Fetch orders for the view
    public function getViewData(): array
    {
        $now = Carbon::now();

        // Slight caching (10s) to reduce DB churn while keeping UI fresh
        $recentHistory = Cache::remember('bar_display:recent_history', 10, function () use ($now) {
            return Order::with(['items.product', 'user', 'booking.room'])
                ->where('destination', 'bar')
                ->whereIn('status', ['ready', 'served', 'paid'])
                ->where('created_at', '>=', $now->copy()->subDays(7)->startOfDay())
                ->latest()
                ->limit(10)
                ->get();
        });

        $itemsSold = Cache::remember('bar_display:items_sold', 10, function () use ($now) {
            return DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->where('orders.destination', 'bar')
                ->whereIn('orders.status', ['ready', 'served', 'paid'])
                ->where('orders.created_at', '>=', $now->copy()->startOfDay())
                ->where('categories.type', 'drink')
                ->select('products.name', DB::raw('SUM(order_items.quantity) as total_sold'))
                ->groupBy('products.id', 'products.name')
                ->orderBy('total_sold', 'desc')
                ->get();
        });

        $orders = Cache::remember('bar_display:active_orders', 5, function () {
            return Order::with(['items.product', 'table', 'user', 'booking.room'])
                ->where('status', 'pending')
                ->where('destination', 'bar')
                ->oldest()
                ->get();
        });

        // Guest drinks waiting at the bar (Phase 7B, D33): same queue, same
        // Mark Ready. Not cached — a new guest card must appear (and chime)
        // on the next poll.
        $guestRequests = GuestRequest::with(['table', 'room', 'confirmedBy', 'items' => fn ($q) => $q->where('status', 'at_bar')])
            ->where('status', GuestRequest::STATUS_CONFIRMED)
            ->whereHas('items', fn ($q) => $q->where('status', 'at_bar'))
            ->get();

        $queue = $orders->map(fn (Order $o) => ['type' => 'order', 'at' => $o->created_at, 'order' => $o])
            ->concat($guestRequests->map(fn (GuestRequest $r) => ['type' => 'guest', 'at' => $r->confirmed_at ?? $r->submitted_at, 'request' => $r]))
            ->sortBy(fn ($entry) => $entry['at']?->getTimestamp() ?? 0)
            ->values();

        $bartenders = GuestShifts::activeBartenderShifts();

        return [
            'orders' => $orders,
            'queue' => $queue,
            'guestWaiting' => $guestRequests->count(),
            'bartenders' => $bartenders->map(fn ($s) => ['id' => $s->user_id, 'name' => $s->user?->name])->values()->all(),
            'presets' => GuestBarReleaseService::REASON_PRESETS,
            'returns' => GuestDeliveryRefusal::with(['request.room', 'order.items'])
                ->where('status', GuestDeliveryRefusal::AWAITING_BAR_RETURN)
                ->oldest('id')
                ->get(),
            'recentHistory' => $recentHistory,
            'itemsSold' => $itemsSold,
            'fridgeRestockList' => (new FridgeStockEstimateService())->belowParProducts($this->barWarehouse()),
        ];
    }

    /**
     * Same hardcoded-fallback convention used everywhere else in this app
     * (OrderSplitter::getBarWarehouseId(), etc.) — id 4 must exist in every
     * environment; this is a safety net, not the primary lookup mechanism.
     */
    private function barWarehouse(): WareHouse
    {
        return WareHouse::find(4) ?? WareHouse::where('type', 'consumer')->orderBy('id')->firstOrFail();
    }

    /**
     * One-tap "topped up to par" — no quantity entered, no InventoryTransaction.
     * Purely resets this product's fridge ESTIMATE; guidance only, never a
     * guard, so it fails soft with a notification rather than a hard error.
     */
    public function markRestocked(int $productId): void
    {
        try {
            $product = Product::findOrFail($productId);
            (new FridgeStockEstimateService())->markRestockedToPar($product, $this->barWarehouse(), auth()->id());

            Notification::make()->title("{$product->name} marked restocked to par")->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not mark restocked')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function markAsReady($orderId)
    {
        // The database part (ready + a room order's stock) is
        // BarOrderService::markReady() since Phase 5 — the same code a guest
        // card's Mark Ready runs (D24, D33).
        $order = (new \App\Services\BarOrderService)->markReady((int) $orderId, auth()->id());

        Cache::forget('bar_display:active_orders');
        Cache::forget('bar_display:recent_history');

        $this->announceReady($order);
    }

    /**
     * Mark Ready on a GUEST card (Phase 7B, D33): one tap creates the real
     * bar order and marks it ready, in one transaction, under every rule
     * the old two-step flow kept (D1 who is marking it ready, D2 credit, D3
     * waiter safety net, D18 price lock, D24 rooms).
     */
    public function markGuestReady(int $requestId, ?int $bartenderUserId = null): void
    {
        $request = GuestRequest::with(['table', 'room'])->find($requestId);

        if (! $this->guestAllowed() || ! $request) {
            return;
        }

        $service = new GuestBarReleaseService;

        try {
            $result = $service->markReady($request, $bartenderUserId ? User::find($bartenderUserId) : null);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't mark {$request->ref} ready", $e->getMessage());

            return;
        }

        Cache::forget('bar_display:active_orders');
        Cache::forget('bar_display:recent_history');

        if ($result === GuestBarReleaseService::RETURNED_TO_WAITERS) {
            UserFeedback::blocked('Returned to waiters', "{$request->table?->name}'s waiter is off shift — the drinks are back on the waiter kiosk for someone to take.");

            return;
        }

        // Rooms: reception sends it up (Room Orders). Tables: the waiter is
        // told the drinks are ready, exactly as for any other bar order.
        if (! $request->isRoom()) {
            foreach ($service->lastOrders as $order) {
                $this->announceReady($order);
            }
        }

        UserFeedback::succeeded(
            ($request->isRoom() ? 'Room '.$request->room?->number : $request->table?->name).' — ready',
            $request->isRoom() ? 'Charged to the room — reception sends it up.' : 'The waiter has been told.'
        );
    }

    public function reduceGuestLine(int $lineId, int $qty, string $reason, ?string $other = null): void
    {
        $line = GuestRequestItem::find($lineId);

        if (! $this->guestAllowed() || ! $line) {
            return;
        }

        try {
            (new GuestBarReleaseService)->reduce($line, $qty, $reason, $other);
        } catch (\Exception $e) {
            UserFeedback::blocked('Not reduced', $e->getMessage());
        }
    }

    public function removeGuestLine(int $lineId, string $reason, ?string $other = null): void
    {
        $line = GuestRequestItem::find($lineId);

        if (! $this->guestAllowed() || ! $line) {
            return;
        }

        try {
            (new GuestBarReleaseService)->remove($line, $reason, $other);
        } catch (\Exception $e) {
            UserFeedback::blocked('Not removed', $e->getMessage());
        }
    }

    /** D26: the refused room bottles are back at the bar. */
    public function confirmReturn(int $refusalId): void
    {
        $refusal = GuestDeliveryRefusal::find($refusalId);

        if (! $this->guestAllowed() || ! $refusal) {
            return;
        }

        try {
            (new RoomDeliveryService)->confirmBarReturn($refusal, auth()->user());
        } catch (\Exception $e) {
            UserFeedback::blocked('Not confirmed', $e->getMessage());

            return;
        }

        UserFeedback::succeeded('Returned ✓', 'A manager now decides the refusal.');
    }

    /** Livewire actions can be called directly — re-check this page's own gate. */
    private function guestAllowed(): bool
    {
        if (static::canAccess()) {
            return true;
        }

        UserFeedback::blocked('Not allowed', 'Only bar staff and managers can mark guest orders ready.');

        return false;
    }

    /** The "Ready!" bell notification every Mark Ready sends to the floor. */
    private function announceReady(Order $order): void
    {
        // 1. Get the list of items (e.g., "2x Rice, 1x Coke")
        $itemList = $order->items->map(function ($item) {
            return "{$item->quantity}x {$item->product_name}";
        })->join(', ');

        // Send database notification to all staff users
        $staffUsers = \App\Models\User::whereHas('roles', function($q) {
            $q->whereIn('name', ['super_admin', 'chef', 'waiter', 'porter']);
        })->get();

        foreach ($staffUsers as $staffUser) {
            Notification::make()
                ->title("Order #{$order->order_number} Ready!")
                ->body("Order #{$order->id} for {$order->origin_label}\n\rItems: {$itemList}\n\r is ready for pickup.")
                ->success()
                ->actions([
                    // Add a button to the notification to jump to the order
                    Action::make('view')
                        ->button()
                        ->url(OrderResource::getUrl('view', ['record' => $order->id])),
                ])
                ->sendToDatabase($staffUser);
        }
    }

    /**
     * Confirming this IS the return — before this, the guest's bill has not
     * changed at all. Only the on-duty bartender's own login can do this
     * (checked against their active, non-stale bartender shift), which is
     * what closes the void-and-pocket loophole: nobody can adjust a bill
     * just by clicking a return button themselves.
     */
    public function confirmAndRestock($returnOrderId) {
        try {
            $returnOrder = Order::with('items.product')->findOrFail($returnOrderId);
            (new ReturnConfirmationService())->confirm($returnOrder, auth()->user());

            Cache::forget('bar_display:active_orders');
            Cache::forget('bar_display:recent_history');

            Notification::make()
                ->title('Return Confirmed')
                ->body('Bill adjusted and inventory restocked.')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Could Not Confirm Return')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * The item never actually came back — closes the ticket without
     * touching the guest's bill or stock at all (both were already
     * untouched pending this decision).
     */
    public function rejectReturn($returnOrderId, string $reason = 'Item was not returned to the bar')
    {
        try {
            $returnOrder = Order::with('items.product')->findOrFail($returnOrderId);
            (new ReturnConfirmationService())->reject($returnOrder, auth()->user(), $reason);

            Cache::forget('bar_display:active_orders');
            Cache::forget('bar_display:recent_history');

            Notification::make()->title('Return Rejected')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could Not Reject Return')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}