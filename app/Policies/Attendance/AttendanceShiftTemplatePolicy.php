<?php

declare(strict_types=1);

namespace App\Policies\Attendance;

use App\Models\Attendance\AttendanceShiftTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AttendanceShiftTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AttendanceShiftTemplate');
    }

    public function view(AuthUser $authUser, AttendanceShiftTemplate $attendanceShiftTemplate): bool
    {
        return $authUser->can('View:AttendanceShiftTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AttendanceShiftTemplate');
    }

    public function update(AuthUser $authUser, AttendanceShiftTemplate $attendanceShiftTemplate): bool
    {
        return $authUser->can('Update:AttendanceShiftTemplate');
    }

    public function delete(AuthUser $authUser, AttendanceShiftTemplate $attendanceShiftTemplate): bool
    {
        return $authUser->can('Delete:AttendanceShiftTemplate');
    }

    public function restore(AuthUser $authUser, AttendanceShiftTemplate $attendanceShiftTemplate): bool
    {
        return $authUser->can('Restore:AttendanceShiftTemplate');
    }

    public function forceDelete(AuthUser $authUser, AttendanceShiftTemplate $attendanceShiftTemplate): bool
    {
        return $authUser->can('ForceDelete:AttendanceShiftTemplate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AttendanceShiftTemplate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AttendanceShiftTemplate');
    }

    public function replicate(AuthUser $authUser, AttendanceShiftTemplate $attendanceShiftTemplate): bool
    {
        return $authUser->can('Replicate:AttendanceShiftTemplate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AttendanceShiftTemplate');
    }
}
