<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\PermissionService;
use App\Services\ReturnConfirmationService;
use App\Services\FridgeStockEstimateService;
use App\Models\Order;
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
            return Order::with('items.product')
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

        return [
            'orders' => Cache::remember('bar_display:active_orders', 5, function () {
                return Order::with(['items.product', 'table'])
                    ->where('status', 'pending')
                    ->where('destination', 'bar')
                    ->oldest()
                    ->get();
            }),
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
            Notification::make()->title('Could not mark restocked')->body($e->getMessage())->danger()->send();
        }
    }

    public function markAsReady($orderId)
    {
        // Use findOrFail so the editor knows it found a real record
        $order = Order::with(['items.product', 'table'])->findOrFail($orderId);
        
        $order->update([
            'status' => 'ready',
            'processed_by_user_id' => auth()->id()
        ]);
        
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
                ->body("Order #{$order->id} for {$order->table->name}\n\rItems: {$itemList}\n\r is ready for pickup.")
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
            Notification::make()->title('Could Not Reject Return')->body($e->getMessage())->danger()->send();
        }
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}