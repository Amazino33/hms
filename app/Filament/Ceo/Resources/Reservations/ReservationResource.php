<?php

namespace App\Filament\Ceo\Resources\Reservations;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\Reservations\Pages\ListReservations;
use App\Models\Booking;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReservationResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('check_in', 'desc')
            ->columns([
                TextColumn::make('guest.name')->label('Guest')->searchable(),
                TextColumn::make('room.number')->label('Room'),
                TextColumn::make('check_in')->date(),
                TextColumn::make('check_out')->date(),
                TextColumn::make('status')->badge(),
                TextColumn::make('nightly_rate')->money('NGN'),
                TextColumn::make('total_price')->label('Total')->money('NGN'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'reserved' => 'Reserved', 'checked_in' => 'Checked In', 'checked_out' => 'Checked Out',
                    'cancelled' => 'Cancelled', 'no_show' => 'No Show',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReservations::route('/')];
    }
}
