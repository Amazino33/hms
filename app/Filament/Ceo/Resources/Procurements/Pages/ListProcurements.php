<?php

namespace App\Filament\Ceo\Resources\Procurements\Pages;

use App\Filament\Ceo\Resources\Procurements\ProcurementResource;
use Filament\Resources\Pages\ListRecords;

class ListProcurements extends ListRecords
{
    protected static string $resource = ProcurementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
