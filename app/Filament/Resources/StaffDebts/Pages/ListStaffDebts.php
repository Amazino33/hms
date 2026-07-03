<?php

namespace App\Filament\Resources\StaffDebts\Pages;

use App\Filament\Resources\StaffDebts\StaffDebtResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffDebts extends ListRecords
{
    protected static string $resource = StaffDebtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add Manual Debt'),
        ];
    }
}
