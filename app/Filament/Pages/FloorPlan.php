<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Table;
use Filament\Actions\Action;
use BackedEnum;
use UnitEnum;

class FloorPlan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';
    protected static string|UnitEnum|null $navigationGroup = 'Restaurant Management';
    protected static ?string $title = 'Live Floor Monitor';
    
    protected string $view = 'filament.pages.floor-plan';

    // Refresh data for the view
    public function getViewData(): array
    {
        return [
            'tables' => Table::with(['orders' => function ($q) {
                $q->whereIn('status', ['pending', 'preparing', 'ready', 'served']) // Include active orders
                    ->with('items.product') // Include order items and products
                    ->latest();
            }])->get(),
        ];
    }

    // Action: Clear a table directly from this screen
    public function clearTable($tableId)
    {
        $table = Table::find($tableId);
        if ($table) {
            $table->update(['status' => 'available']);
            // Optional: Close any pending orders if you want to force it
        }
        
        $this->dispatch('notify', 'Table Cleared'); // Simple notification
    }

    
    public static function canAccess(): bool
    {
        // Only Super Admins and waiter can see this
        return auth()->user()->hasRole(['super_admin', 'manager', 'waiter']);
    }
}