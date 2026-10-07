<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\Attendance\AttendanceOnlyRoleService;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private bool $attendanceOnly = false;

    /**
     * staff_type is a form-only control, so it is pulled out before the
     * record is written and applied afterwards through the service — which is
     * what actually enforces that attendance_only is held alone.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->attendanceOnly = ($this->data['staff_type'] ?? 'app') === 'attendance_only';

        unset($data['staff_type']);

        if ($this->attendanceOnly) {
            // Never a blank string or a throwaway hash: a record with no
            // credentials must not be authenticatable by any path.
            $data['password'] = null;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->attendanceOnly) {
            AttendanceOnlyRoleService::makeAttendanceOnly($this->record, auth()->user());
        }
    }
}
