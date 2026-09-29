<?php

namespace App\Filament\Resources\AttendanceLogs\Pages;

use App\Filament\Resources\AttendanceLogs\AttendanceLogResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAttendanceLogs extends ManageRecords
{
    protected static string $resource = AttendanceLogResource::class;

    /**
     * No create action: daily_attendances is a database view aggregating
     * punches the terminal pushed. There is nothing here a person can
     * legitimately add by hand, and the insert would fail at the database.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
