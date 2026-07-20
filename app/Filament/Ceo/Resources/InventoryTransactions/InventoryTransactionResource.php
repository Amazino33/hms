<?php

namespace App\Filament\Ceo\Resources\InventoryTransactions;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\InventoryTransactions\Pages\ListInventoryTransactions;
use App\Models\InventoryTransaction;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InventoryTransactionResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = InventoryTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->formatStateUsing(fn ($record) => ($record->product?->name ?? '—').($record->product?->trashed() ? ' (deleted)' : ''))
                    ->searchable(),
                TextColumn::make('warehouse.name')->label('Location'),
                TextColumn::make('type')->badge(),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('user.name')->label('By'),
                TextColumn::make('created_at')->dateTime('M j, Y g:ia')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'purchase' => 'Purchase', 'transfer_out' => 'Transfer Out', 'transfer_in' => 'Transfer In',
                    'sale' => 'Sale', 'adjustment' => 'Adjustment', 'opening_balance' => 'Opening Balance',
                    'transfer_reversal_in' => 'Transfer Reversal In',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInventoryTransactions::route('/')];
    }
}
