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
    protected string $view = 'filament.pages.Bar-display';

    // Fetch orders for the view
    public function getViewData(): array
    {
        return [
            'orders' => Order::with('items')
                ->where('status', 'pending') // Only show active orders
                ->where('destination', 'bar') // only bar orders
                ->oldest() // First in, First out
                ->get(),
        ];
    }

    public function markAsReady($orderId)
    {
        // Use findOrFail so the editor knows it found a real record
        $order = Order::findOrFail($orderId);
        
        $order->update(['status' => 'ready']);
        
        // 1. Get the list of items (e.g., "2x Rice, 1x Coke")
        $itemList = $order->items->map(function ($item) {
            return "{$item->quantity}x {$item->product->name}";
        })->join(', ');
        
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
            ->sendToDatabase($order->user);
    }

    public static function canAccess(): bool
    {
        // Only Super Admins and Chefs can see this
        return auth()->user()->hasRole(['super_admin', 'manager', 'bartender']);
    }
}