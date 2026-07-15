<?php

namespace App\Filament\Ceo\Resources\StaffDebts\Pages;

use App\Filament\Ceo\Resources\StaffDebts\StaffDebtResource;
use Filament\Resources\Pages\ListRecords;

class ListStaffDebts extends ListRecords
{
    protected static string $resource = StaffDebtResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
