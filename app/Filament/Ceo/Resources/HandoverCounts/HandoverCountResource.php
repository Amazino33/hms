<?php

namespace App\Filament\Ceo\Resources\HandoverCounts;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\HandoverCounts\Pages\ListHandoverCounts;
use App\Models\CountSession;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class HandoverCountResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = CountSession::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = 'Handover Counts';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('warehouse.name')->label('Location'),
                TextColumn::make('type')->badge(),
                TextColumn::make('outgoingUser.name')->label('Outgoing'),
                TextColumn::make('incomingUser.name')->label('Incoming'),
                TextColumn::make('status')->badge(),
                TextColumn::make('opened_at')->dateTime('M j, g:ia')->sortable(),
                TextColumn::make('reviewed_at')->dateTime('M j, g:ia')->sortable(),
                TextColumn::make('total_shortage_value')->label('Shortage Value')->money('NGN'),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'bar_handover' => 'Bar Handover', 'kitchen_handover' => 'Kitchen Handover', 'main_store_stocktake' => 'Main Store Stocktake',
                ]),
                SelectFilter::make('status')->options([
                    'counting' => 'Counting', 'declared' => 'Declared', 'pending_review' => 'Pending Review',
                    'reviewed' => 'Reviewed', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListHandoverCounts::route('/')];
    }
}
