<?php

namespace App\Filament\Ceo\Resources\Folios;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\Folios\Pages\ListFolios;
use App\Models\Folio;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FolioResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = Folio::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('booking.room.number')->label('Room'),
                TextColumn::make('booking.guest.name')->label('Guest')->searchable(),
                TextColumn::make('booking.status')->label('Booking Status')->badge(),
                TextColumn::make('balance')->label('Balance')->state(fn (Folio $record) => $record->balance())->money('NGN'),
                TextColumn::make('created_at')->dateTime('M j, Y g:ia')->sortable(),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFolios::route('/')];
    }
}
