<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use BackedEnum;
use Illuminate\Support\Facades\Auth;
use App\Models\StockTransfer;

class ReceiveTransfers extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-inbox';
    protected static ?string $navigationLabel = 'Receive Transfers';
    protected string $view = 'filament.pages.receive-transfers';

    public function getViewData(): array
    {
        $user = Auth::user();
        $warehouseId = null;
        $warehouseName = null;

        if ($user->hasRole('bartender')) {
            // Find the bar warehouse (consumer type, typically the first consumer warehouse)
            $barWarehouse = \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->first();
            if ($barWarehouse) {
                $warehouseId = $barWarehouse->id;
                $warehouseName = $barWarehouse->name;
            }
        }

        if ($user->hasRole('chef')) {
            // Find the kitchen warehouse (consumer type, typically the second consumer warehouse)
            $consumerWarehouses = \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->get();
            if ($consumerWarehouses->count() > 1) {
                $kitchenWarehouse = $consumerWarehouses[1]; // Second consumer warehouse
                $warehouseId = $kitchenWarehouse->id;
                $warehouseName = $kitchenWarehouse->name;
            } elseif ($consumerWarehouses->count() == 1) {
                // If only one consumer warehouse, use it for chef
                $warehouseId = $consumerWarehouses[0]->id;
                $warehouseName = $consumerWarehouses[0]->name;
            }
        }

        if ($user->hasRole('storekeeper') || $user->hasRole('super_admin')) {
            // Storekeeper can see all transfers regardless of warehouse
            $transfers = StockTransfer::with(['items','fromWarehouse','toWarehouse'])->whereIn('status', ['pending','sent'])->latest()->get();
            return [
                'transfers' => $transfers,
                'warehouseId' => 'all',
                'warehouseName' => 'All Warehouses',
            ];
        }

        $transfers = collect();
        if ($warehouseId) {
            $transfers = StockTransfer::where('to_warehouse_id', $warehouseId)
                ->whereIn('status', ['pending','sent'])
                ->with(['items','fromWarehouse','toWarehouse'])
                ->latest()
                ->get();
        }

        return [
            'transfers' => $transfers,
            'warehouseId' => $warehouseId,
            'warehouseName' => $warehouseName,
        ];
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return $user->hasAnyRole(['chef','bartender','storekeeper','super_admin']);
    }
}
