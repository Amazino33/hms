<?php

namespace App\Filament\Resources\StaffDebts\Pages;

use App\Filament\Resources\StaffDebts\StaffDebtResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffDebt extends CreateRecord
{
    protected static string $resource = StaffDebtResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reason'] = 'manual';
        $data['status'] = 'open';
        $data['created_by'] = auth()->id();

        return $data;
    }
}
