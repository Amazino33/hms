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
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class KitchenDisplay extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-fire';
    protected static ?string $navigationLabel = 'Kitchen Display';
    protected string $view = 'filament.pages.kitchen-display';

    // Fetch orders for the view
    public function getViewData(): array
    {
        $now = Carbon::now();

        // Short caches keep UI fresh while easing DB load
        $recentHistory = Cache::remember('kitchen_display:recent_history', 10, function () use ($now) {
            return Order::with(['items.product', 'items.menuItem.recipes.ingredient', 'user', 'booking.room'])
                ->where('destination', 'kitchen')
                ->whereIn('status', ['ready', 'served', 'paid'])
                ->where('created_at', '>=', $now->copy()->subDays(7)->startOfDay())
                ->latest()
                ->limit(10)
                ->get();
        });

        $itemsSold = Cache::remember('kitchen_display:items_sold', 10, function () use ($now) {
            return DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->where('orders.destination', 'kitchen')
                ->whereIn('orders.status', ['ready', 'served', 'paid'])
                ->where('orders.created_at', '>=', $now->copy()->startOfDay())
                ->where('categories.type', 'food')
                ->select('products.name', DB::raw('SUM(order_items.quantity) as total_sold'))
                ->groupBy('products.id', 'products.name')
                ->orderBy('total_sold', 'desc')
                ->get();
        });

        return [
            'orders' => Cache::remember('kitchen_display:active_orders', 5, function () {
                return Order::with(['items.product', 'items.menuItem.recipes.ingredient', 'table', 'user', 'booking.room'])
                    ->where('status', 'pending')
                    ->where('destination', 'kitchen')
                    ->oldest()
                    ->get();
            }),
            'recentHistory' => $recentHistory,
            'itemsSold' => $itemsSold,
        ];
    }

    public function markAsReady($orderId)
    {
        // Scoped to pending+kitchen, not a bare findOrFail — Livewire
        // methods are callable with any arguments from the client, and
        // without this guard, calling markAsReady() on an already-ready/
        // served/paid order (or a BAR-destination one) would silently
        // flip its status back and re-fire the "Ready!" notification.
        // Row-locked inside a transaction because a room order's stock
        // deduction now happens right here — two concurrent clicks must
        // not deduct twice.
        $order = DB::transaction(function () use ($orderId) {
            $order = Order::with(['items.product', 'items.menuItem.recipes.ingredient', 'table', 'booking.room'])
                ->where('status', 'pending')
                ->where('destination', 'kitchen')
                ->lockForUpdate()
                ->findOrFail($orderId);

            $order->update([
                'status' => 'ready',
                'processed_by_user_id' => auth()->id()
            ]);

            // Every other destination already deducted stock at order
            // creation (OrderSplitter::handle()); a room order deferred it
            // until now, this exact transition.
            if ($order->booking_id) {
                InventoryService::deductInventoryForOrderItems($order);
            }

            return $order;
        });

        Cache::forget('kitchen_display:active_orders');
        Cache::forget('kitchen_display:recent_history');

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
     * changed at all. Only the on-duty chef's own login can do this (checked
     * against their active, non-stale chef shift).
     */
    public function confirmAndRestock($returnOrderId) {
        try {
            $returnOrder = Order::with('items.product')->findOrFail($returnOrderId);
            (new ReturnConfirmationService())->confirm($returnOrder, auth()->user());

            Cache::forget('kitchen_display:active_orders');
            Cache::forget('kitchen_display:recent_history');

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
                ->send();
        }
    }

    /**
     * The item never actually came back — closes the ticket without
     * touching the guest's bill or stock at all.
     */
    public function rejectReturn($returnOrderId, string $reason = 'Item was not returned to the kitchen')
    {
        try {
            $returnOrder = Order::with('items.product')->findOrFail($returnOrderId);
            (new ReturnConfirmationService())->reject($returnOrder, auth()->user(), $reason);

            Cache::forget('kitchen_display:active_orders');
            Cache::forget('kitchen_display:recent_history');

            Notification::make()->title('Return Rejected')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could Not Reject Return')->body($e->getMessage())->danger()->send();
        }
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}