<?php

namespace App\Filament\Ceo\Resources\Reservations\Pages;

use App\Filament\Ceo\Resources\Reservations\ReservationResource;
use Filament\Resources\Pages\ListRecords;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
