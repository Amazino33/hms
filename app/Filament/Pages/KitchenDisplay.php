<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\PermissionService;
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
            return Order::with(['items.product', 'items.menuItem.recipes.ingredient'])
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
                return Order::with(['items.product', 'items.menuItem.recipes.ingredient', 'table'])
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
        // Use findOrFail so the editor knows it found a real record
        $order = Order::with(['items.product', 'items.menuItem.recipes.ingredient', 'table'])->findOrFail($orderId);
        
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

    public function confirmAndRestock($returnOrderId) {
        try {
            DB::transaction(function () use ($returnOrderId) {
                // Fetch the specific return order and its items
                $returnOrder = Order::with('items.product')->findOrFail($returnOrderId);

                // Prevent double clicking by checking if it's already processed
                if ($returnOrder->status === 'returned') {
                    throw new \Exception('This return has already been processed.');
                }

                // Restocking (products + menu-item ingredients) and its
                // InventoryTransaction/IngredientTransaction records are
                // handled centrally by OrderObserver on this status change —
                // there is no separate mutation path here.
                $returnOrder->update(['status' => 'returned']);
            });

            // 👇 BUST THE CACHE: Forces the UI to refresh instantly
            Cache::forget('kitchen_display:active_orders');
            Cache::forget('kitchen_display:recent_history');

            // 👇 USE FILAMENT NOTIFICATION: Replaces standard session flash
            Notification::make()
                ->title('Restocked Successfully')
                ->body('Return processed and inventory updated.')
                ->success()
                ->send();

        } catch (\Exception $e) {
            // 👇 USE FILAMENT NOTIFICATION FOR ERRORS
            Notification::make()
                ->title('Restock Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}