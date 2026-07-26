<?php

namespace App\Filament\Ceo\Resources\MenuItems;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\MenuItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The "selling price" side of the ingredient cost story — a menu item is
 * what's actually sold to a guest, assembled from ingredients via Recipe.
 * Cost here is MenuItem::getTotalRecipeCostAttribute() (quantity_needed x
 * each ingredient's current cost_per_unit), the same calculation the app
 * already uses elsewhere — this just surfaces it to the CEO.
 */
class MenuItemResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = MenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cake';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Menu Items';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'recipes.ingredient']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('category.name')->label('Category')->searchable(),
                TextColumn::make('sale_price')->label('Selling Price')->money('NGN')->sortable(),
                TextColumn::make('total_recipe_cost')
                    ->label('Recipe Cost')
                    ->state(fn (MenuItem $record) => (float) $record->total_recipe_cost)
                    ->money('NGN'),
                TextColumn::make('margin')
                    ->label('Margin')
                    ->state(fn (MenuItem $record) => (float) $record->sale_price - (float) $record->total_recipe_cost)
                    ->money('NGN')
                    ->color(fn (float $state) => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('margin_pct')
                    ->label('Margin %')
                    ->state(fn (MenuItem $record) => (float) $record->sale_price > 0
                        ? round((((float) $record->sale_price - (float) $record->total_recipe_cost) / (float) $record->sale_price) * 100, 1)
                        : null)
                    ->suffix('%')
                    ->placeholder('—'),
                IconColumn::make('available_for_sale')->label('Available')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Category')->relationship('category', 'name'),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMenuItems::route('/')];
    }
}
