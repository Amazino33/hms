<?php

namespace App\Filament\Ceo\Resources\RoomSupplyTransactions;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\RoomSupplyTransactions\Pages\ListRoomSupplyTransactions;
use App\Models\RoomSupplyTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class RoomSupplyTransactionResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = RoomSupplyTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Room Supply Transactions';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('roomSupply.name')->label('Room Supply')->default('—')->searchable(),
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
                    'adjustment' => 'Adjustment', 'opening_balance' => 'Opening Balance',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRoomSupplyTransactions::route('/')];
    }
}
