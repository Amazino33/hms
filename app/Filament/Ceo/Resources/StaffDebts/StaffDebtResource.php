<?php

namespace App\Filament\Ceo\Resources\StaffDebts;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\StaffDebts\Pages\ListStaffDebts;
use App\Models\StaffDebt;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StaffDebtResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = StaffDebt::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Staff')->searchable(),
                TextColumn::make('reason'),
                TextColumn::make('amount')->money('NGN')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime('M j, Y g:ia')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Open', 'partially_settled' => 'Partially Settled', 'settled' => 'Settled',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStaffDebts::route('/')];
    }
}
