<?php

namespace App\Filament\Resources\ShiftManagement\Pages;

use App\Filament\Resources\ShiftManagement\ShiftManagementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewShiftManagement extends ViewRecord
{
    protected static string $resource = ShiftManagementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No edit action
        ];
    }
}
