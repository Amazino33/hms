<?php

namespace App\Filament\Ceo\Resources\AttendanceLogs\Pages;

use App\Filament\Ceo\Resources\AttendanceLogs\AttendanceLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAttendanceLogs extends ManageRecords
{
    protected static string $resource = AttendanceLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
