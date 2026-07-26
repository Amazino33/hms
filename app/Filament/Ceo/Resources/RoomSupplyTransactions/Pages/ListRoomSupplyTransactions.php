<?php

namespace App\Filament\Ceo\Resources\RoomSupplyTransactions\Pages;

use App\Filament\Ceo\Resources\RoomSupplyTransactions\RoomSupplyTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListRoomSupplyTransactions extends ListRecords
{
    protected static string $resource = RoomSupplyTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
