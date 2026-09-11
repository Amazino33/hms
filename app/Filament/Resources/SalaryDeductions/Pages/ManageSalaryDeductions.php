<?php

namespace App\Filament\Resources\SalaryDeductions\Pages;

use App\Filament\Resources\SalaryDeductions\SalaryDeductionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSalaryDeductions extends ManageRecords
{
    protected static string $resource = SalaryDeductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
