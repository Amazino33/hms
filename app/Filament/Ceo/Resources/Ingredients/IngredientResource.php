<?php

namespace App\Filament\Ceo\Resources\Ingredients;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\Ingredients\Pages\ListIngredients;
use App\Models\Ingredient;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Ingredients aren't sold directly to a guest — they're inputs to Menu
 * Items via Recipe — so this shows cost only, not a "selling price"
 * (that side of the margin story lives on the CEO MenuItems resource,
 * which computes recipe cost against each dish's sale_price).
 */
class IngredientResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = Ingredient::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Ingredients';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('category')->searchable(),
                TextColumn::make('unit_name')->label('Unit'),
                TextColumn::make('cost_per_unit')->label('Cost per Unit')->money('NGN')->sortable(),
                TextColumn::make('current_stock')->label('Current Stock')->numeric(2),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn () => Ingredient::query()->distinct()->pluck('category', 'category')->filter()->all()),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIngredients::route('/')];
    }
}
