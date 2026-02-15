<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use App\Services\PermissionService;
use App\Models\Product;
use App\Filament\Widgets\StockValuationOverview; 
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use UnitEnum;

class StockValuation extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $title = 'Stock Level & Valuation';
    protected string $view = 'filament.pages.stock-valuation';

    // 👇 THIS IS THE CRITICAL FIX
    protected function getHeaderWidgets(): array
    {
        return [
            StockValuationOverview::class, // ✅ Returns the string name, NOT the object
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // 1. Get the sum of stock from all warehouses
            ->query(Product::query()->withSum('inventory', 'quantity'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                // The Stock Count (Sum of Bar + Store)
                TextColumn::make('inventory_sum_quantity')
                    ->label('Total Stock')
                    ->sortable(),

                // Cost Price
                TextColumn::make('cost_price')
                    ->label('Cost Price')
                    ->money('NGN'),

                // Total Cost (Calculated)
                TextColumn::make('total_cost_value')
                    ->label('Total Cost')
                    ->money('NGN')
                    ->state(fn (Product $record) => $record->cost_price * $record->inventory_sum_quantity)
                    ->color('danger'),
                    // ❌ REMOVED: ->summarize(...) to prevent the SQL error

                // Selling Price
                TextColumn::make('price')
                    ->label('Selling')
                    ->money('NGN'),

                // Total Sales (Calculated)
                TextColumn::make('total_sales_value')
                    ->label('Total Sales')
                    ->money('NGN')
                    ->state(fn (Product $record) => $record->price * $record->inventory_sum_quantity)
                    ->color('success')
                    ->weight('bold'),
                    // ❌ REMOVED: ->summarize(...) to prevent the SQL error
            ])
            ->defaultSort('inventory_sum_quantity', 'desc')
            ->striped();
    }
    
    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}