<?php

namespace App\Filament\Ceo\Resources\RoomSupplies;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\RoomSupplies\Pages\ListRoomSupplies;
use App\Models\RoomSupply;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class RoomSupplyResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = RoomSupply::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Room Supplies';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('unit')->label('Unit'),
                TextColumn::make('cost_per_unit')->label('Cost per Unit')->money('NGN')->sortable(),
                TextColumn::make('current_stock')->label('Current Stock')->numeric(2),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRoomSupplies::route('/')];
    }
}
