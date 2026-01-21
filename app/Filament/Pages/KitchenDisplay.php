<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use App\Models\Order;
use Filament\Notifications\Notification;

class KitchenDisplay extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-fire';
    protected static ?string $navigationLabel = 'Kitchen Display';
    protected string $view = 'filament.pages.kitchen-display';

    // Fetch orders for the view
    public function getViewData(): array
    {
        return [
            'orders' => Order::with('items')
                ->where('status', 'pending') // Only show active orders
                ->oldest() // First in, First out
                ->get(),
        ];
    }

    public function markAsReady($orderId)
{
    // Use findOrFail so the editor knows it found a real record
    $order = Order::findOrFail($orderId);
    
    $order->update(['status' => 'ready']);
    
    Notification::make()
        ->title("Order #{$order->order_number} Ready!")
        ->success()
        ->send();
}
}