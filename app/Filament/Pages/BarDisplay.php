<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use BackedEnum;
use Filament\Pages\Page;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class BarDisplay extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Bar Display';
    protected string $view = 'filament.pages.bar-display';

    // Fetch orders for the view
    public function getViewData(): array
    {
        // Get recent completed orders for history (last 7 days instead of just today)
        $recentHistory = Order::with('items.product')
            ->where('destination', 'bar')
            ->whereIn('status', ['ready', 'served', 'paid'])
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->latest()
            ->limit(10)
            ->get();

        // Get total items sold today
        $itemsSold = \DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.destination', 'bar')
            ->whereIn('orders.status', ['ready', 'served', 'paid'])
            ->where('orders.created_at', '>=', now()->startOfDay())
            ->where('categories.type', 'drink')
            ->select('products.name', \DB::raw('SUM(order_items.quantity) as total_sold'))
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_sold', 'desc')
            ->get();

        return [
            'orders' => Order::with('items')
                ->where('status', 'pending') // Only show active orders
                ->where('destination', 'bar') // only bar orders
                ->oldest() // First in, First out
                ->get(),
            'recentHistory' => $recentHistory,
            'itemsSold' => $itemsSold,
        ];
    }

    public function markAsReady($orderId)
    {
        // Use findOrFail so the editor knows it found a real record
        $order = Order::findOrFail($orderId);
        
        $order->update([
            'status' => 'ready',
            'processed_by_user_id' => auth()->id()
        ]);
        
        // 1. Get the list of items (e.g., "2x Rice, 1x Coke")
        $itemList = $order->items->map(function ($item) {
            return "{$item->quantity}x {$item->product->name}";
        })->join(', ');
        
        // Send database notification to all staff users
        $staffUsers = \App\Models\User::whereHas('roles', function($q) {
            $q->whereIn('name', ['super_admin', 'chef', 'waiter']);
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

    public static function canAccess(): bool
    {
        // Only Super Admins and Chefs can see this
        return auth()->user()->hasRole(['super_admin', 'manager', 'bartender']);
    }
}