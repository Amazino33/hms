<?php

namespace App\Filament\Ceo\Resources\IngredientTransactions;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\IngredientTransactions\Pages\ListIngredientTransactions;
use App\Models\IngredientTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The kitchen-track twin of InventoryTransactionResource (which only ever
 * covered Products/bar stock) — ingredients move through a completely
 * separate table by design (see CLAUDE.md's "two parallel stock tracks"),
 * so the CEO panel had zero visibility into kitchen stock movement until
 * this resource existed.
 */
class IngredientTransactionResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = IngredientTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Ingredient Transactions';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('ingredient.name')->label('Ingredient')->default('—')->searchable(),
                TextColumn::make('warehouse.name')->label('Location'),
                TextColumn::make('type')->badge(),
                TextColumn::make('quantity')->numeric(2),
                TextColumn::make('cost_per_unit')->label('Cost/Unit')->money('NGN')->placeholder('—'),
                TextColumn::make('user.name')->label('By'),
                TextColumn::make('created_at')->dateTime('M j, Y g:ia')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'purchase' => 'Purchase', 'usage' => 'Usage', 'transfer' => 'Transfer',
                    'adjustment' => 'Adjustment', 'return' => 'Return', 'opening_balance' => 'Opening Balance',
                    'transfer_reversal_in' => 'Transfer Reversal In', 'damage_write_off' => 'Damage Write-Off',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIngredientTransactions::route('/')];
    }
}
