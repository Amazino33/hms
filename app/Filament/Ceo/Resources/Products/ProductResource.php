<?php

namespace App\Filament\Ceo\Resources\Products;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ProductResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Products';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withTrashed()->with('category'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->formatStateUsing(fn (Product $record) => $record->name.($record->trashed() ? ' (deleted)' : '')),
                TextColumn::make('category.name')->label('Category')->searchable(),
                TextColumn::make('price')->label('Selling Price')->money('NGN')->sortable(),
                TextColumn::make('cost_price')->label('Cost Price')->money('NGN')->sortable(),
                TextColumn::make('margin')
                    ->label('Margin')
                    ->state(fn (Product $record) => (float) $record->price - (float) $record->cost_price)
                    ->money('NGN')
                    ->color(fn (float $state) => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('margin_pct')
                    ->label('Margin %')
                    ->state(fn (Product $record) => (float) $record->price > 0
                        ? round((((float) $record->price - (float) $record->cost_price) / (float) $record->price) * 100, 1)
                        : null)
                    ->suffix('%')
                    ->placeholder('—'),
                TextColumn::make('last_cost_price')
                    ->label('Latest Procurement Cost')
                    ->money('NGN')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Category')->relationship('category', 'name'),
                TrashedFilter::make(),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/')];
    }
}
