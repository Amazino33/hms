<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use BackedEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Services\PermissionService;
use App\Models\StockTransfer;

class StorekeeperTransfers extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Stock Transfers';
    protected string $view = 'filament.pages.storekeeper-transfers';

    public function getViewData(): array
    {
        // Short cache for lookup lists
        $products = Cache::remember('storekeeper:products', 60, fn () => Product::with('category')->orderBy('name')->get());
        $warehouses = Cache::remember('storekeeper:warehouses', 60, fn () => WareHouse::orderBy('name')->get());

        $page = request()->get('page', 1);
        $recent = StockTransfer::with(['items','fromWarehouse','toWarehouse'])->latest()->paginate(10, ['*'], 'page', $page);

        return [
            'products' => $products,
            'warehouses' => $warehouses,
            'recentTransfers' => $recent,
        ];
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
