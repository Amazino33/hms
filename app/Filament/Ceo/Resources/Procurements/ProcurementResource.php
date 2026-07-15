<?php

namespace App\Filament\Ceo\Resources\Procurements;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\Procurements\Pages\ListProcurements;
use App\Models\Procurement;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProcurementResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = Procurement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('purchased_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('location.name')->label('Location'),
                TextColumn::make('supplier_name')->label('Supplier'),
                TextColumn::make('purchased_at')->date(),
                TextColumn::make('total_cost')->money('NGN')->sortable(),
                TextColumn::make('recordedBy.name')->label('Recorded By'),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListProcurements::route('/')];
    }
}
