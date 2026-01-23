<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use App\Models\Order;
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

    public static function canAccess(): bool
    {
        // Only Super Admins and Chefs can see this
        return auth()->user()->hasRole(['super_admin', 'manager', 'bartender']);
    }
}