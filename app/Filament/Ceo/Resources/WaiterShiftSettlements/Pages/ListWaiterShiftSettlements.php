<?php

namespace App\Filament\Ceo\Resources\WaiterShiftSettlements\Pages;

use App\Filament\Ceo\Resources\WaiterShiftSettlements\WaiterShiftSettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListWaiterShiftSettlements extends ListRecords
{
    protected static string $resource = WaiterShiftSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
