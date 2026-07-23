<?php

namespace App\Filament\Ceo\Resources\Procurements\Pages;

use App\Filament\Ceo\Resources\Procurements\ProcurementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProcurement extends ViewRecord
{
    protected static string $resource = ProcurementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
