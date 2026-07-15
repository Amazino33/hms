<?php

namespace App\Filament\Ceo\Resources\ReceptionistShiftSettlements\Pages;

use App\Filament\Ceo\Resources\ReceptionistShiftSettlements\ReceptionistShiftSettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListReceptionistShiftSettlements extends ListRecords
{
    protected static string $resource = ReceptionistShiftSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
