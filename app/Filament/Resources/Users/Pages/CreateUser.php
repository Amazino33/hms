<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\Attendance\AttendanceOnlyRoleService;
use App\Services\StaffAccessGuard;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * On for this page so the role check in afterCreate() can roll the
     * whole create back — the admin panel itself runs without transactions.
     */
    protected ?bool $hasDatabaseTransactions = true;

    private bool $attendanceOnly = false;

    /**
     * staff_type is a form-only control, so it is pulled out before the
     * record is written and applied afterwards through the service — which is
     * what actually enforces that attendance_only is held alone.
     *
     * Without assign_staff_roles a new person can only be attendance-only,
     * whatever the submitted state says: an app user needs a role, and
     * giving one is a role change.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->attendanceOnly = ($this->data['staff_type'] ?? 'app') === 'attendance_only'
            || ! StaffAccessGuard::canAssignRoles(auth()->user());

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
        if (! StaffAccessGuard::canAssignRoles(auth()->user()) && $this->record->roles()->exists()) {
            Notification::make()->danger()->title('Only the super admin can give staff roles')->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }

        if ($this->attendanceOnly) {
            AttendanceOnlyRoleService::makeAttendanceOnly($this->record, auth()->user());
        }
    }
}
