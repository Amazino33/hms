<?php

namespace App\Filament\Ceo\Resources\RoomSupplies\Pages;

use App\Filament\Ceo\Resources\RoomSupplies\RoomSupplyResource;
use Filament\Resources\Pages\ListRecords;

class ListRoomSupplies extends ListRecords
{
    protected static string $resource = RoomSupplyResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
