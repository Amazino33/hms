<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Services\PermissionService;
use App\Services\ReturnConfirmationService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class KitchenDisplay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

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
        (new \App\Services\KitchenOrderService)->markReady($orderId, auth()->id());

        Cache::forget('kitchen_display:active_orders');
        Cache::forget('kitchen_display:recent_history');
    }

    /**
     * Confirming this IS the return — before this, the guest's bill has not
     * changed at all. Only the on-duty chef's own login can do this (checked
     * against their active, non-stale chef shift).
     */
    public function confirmAndRestock($returnOrderId)
    {
        try {
            $returnOrder = Order::with('items.product')->findOrFail($returnOrderId);
            (new ReturnConfirmationService)->confirm($returnOrder, auth()->user());

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
                ->persistent()
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
            (new ReturnConfirmationService)->reject($returnOrder, auth()->user(), $reason);

            Cache::forget('kitchen_display:active_orders');
            Cache::forget('kitchen_display:recent_history');

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
