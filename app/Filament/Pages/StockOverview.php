<?php

namespace App\Filament\Pages;

use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\WareHouse;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only, per-warehouse stock levels. Products and ingredients live on
 * entirely separate tables by design, so this page toggles which one the
 * table is backed by rather than faking a merged query — either way, this
 * page has no create/edit/delete actions of any kind.
 */
class StockOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Stock Overview';
    protected static ?string $title = 'Stock Overview';
    protected string $view = 'filament.pages.stock-overview';

    public string $viewMode = 'products';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['products', 'ingredients'], true) ? $mode : 'products';
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        if ($this->viewMode === 'ingredients') {
            return $table
                ->query(IngredientInventoryItem::query()->with(['ingredient', 'warehouse']))
                ->columns([
                    TextColumn::make('ingredient.name')
                        ->label('Ingredient')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('ingredient.sku')
                        ->label('SKU')
                        ->toggleable(),
                    TextColumn::make('warehouse.name')
                        ->label('Warehouse')
                        ->sortable(),
                    TextColumn::make('quantity')
                        ->label('Current Stock')
                        ->numeric()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('warehouse_id')
                        ->label('Warehouse')
                        ->options(fn () => WareHouse::pluck('name', 'id')),
                    SelectFilter::make('ingredient_id')
                        ->label('Ingredient')
                        ->options(fn () => Ingredient::pluck('name', 'id')),
                ])
                ->defaultSort('ingredient.name');
        }

        return $table
            ->query(InventoryItem::query()->with(['product', 'warehouse']))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->toggleable(),
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Current Stock')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('Warehouse')
                    ->options(fn () => WareHouse::pluck('name', 'id')),
            ])
            ->defaultSort('product.name');
    }
}
