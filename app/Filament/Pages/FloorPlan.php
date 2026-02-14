<?php

namespace App\Filament\Pages;

use App\Services\PermissionService;
use App\Models\Table;
use Filament\Actions\Action;
use BackedEnum;
use Filament\Pages\Page;
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
                    ->with(['items.product', 'user']) // Include order items, products, and user
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
        return PermissionService::canAccessPage(self::class);
    }
}