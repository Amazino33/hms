<?php

namespace App\Filament\Ceo\Resources\WaiterShiftSettlements;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\WaiterShiftSettlements\Pages\ListWaiterShiftSettlements;
use App\Models\Shift;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WaiterShiftSettlementResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Waiter Settlements';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'waiter');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ended_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Waiter')->searchable(),
                TextColumn::make('started_at')->dateTime('M j, g:ia')->sortable(),
                TextColumn::make('ended_at')->dateTime('M j, g:ia')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('declared_cash')->label('Declared Cash')->money('NGN'),
                TextColumn::make('cashier_counted_cash')->label('Cashier Counted')->money('NGN'),
                TextColumn::make('cash_variance')->label('Variance')->money('NGN'),
                TextColumn::make('surplus_amount')->label('Surplus')->money('NGN'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active', 'awaiting_cashier' => 'Awaiting Cashier', 'confirmed' => 'Confirmed',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWaiterShiftSettlements::route('/')];
    }
}
