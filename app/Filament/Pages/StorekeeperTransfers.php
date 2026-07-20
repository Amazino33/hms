<?php

namespace App\Filament\Pages;

use App\Models\Ingredient;
use App\Models\Product;
use Filament\Pages\Page;
use BackedEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Services\PermissionService;
use App\Models\StockTransfer;
use App\Models\WareHouse;

class StorekeeperTransfers extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Stock Transfers';
    protected string $view = 'filament.pages.storekeeper-transfers';

    // Defer loading of paginated recent transfers (which can be expensive)
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function getViewData(): array
    {
        // Short cache for lookup lists (these are cheap and useful immediately)
        $products = Cache::remember('storekeeper:products', 60, fn () => Product::with('category')->orderBy('name')->get());
        $ingredients = Cache::remember('storekeeper:ingredients', 60, fn () => Ingredient::orderBy('name')->get());
        $warehouses = Cache::remember('storekeeper:warehouses', 60, fn () => WareHouse::orderBy('name')->get());

        if (! $this->ready) {
            return [
                'products' => $products,
                'ingredients' => $ingredients,
                'warehouses' => $warehouses,
                'recentTransfers' => collect(),
            ];
        }

        $page = request()->get('page', 1);
        $recent = StockTransfer::with([
            'items.product', 'items.discrepancy',
            'ingredientItems.ingredient', 'ingredientItems.discrepancy',
            'fromWarehouse', 'toWarehouse',
        ])->latest()->paginate(10, ['*'], 'page', $page);

        return [
            'products' => $products,
            'ingredients' => $ingredients,
            'warehouses' => $warehouses,
            'recentTransfers' => $recent,
        ];
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
