<?php

declare(strict_types=1);

namespace App\Policies\Attendance;

use App\Models\Attendance\AttendanceDeviceUser;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AttendanceDeviceUserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AttendanceDeviceUser');
    }

    public function view(AuthUser $authUser, AttendanceDeviceUser $attendanceDeviceUser): bool
    {
        return $authUser->can('View:AttendanceDeviceUser');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AttendanceDeviceUser');
    }

    public function update(AuthUser $authUser, AttendanceDeviceUser $attendanceDeviceUser): bool
    {
        return $authUser->can('Update:AttendanceDeviceUser');
    }

    public function delete(AuthUser $authUser, AttendanceDeviceUser $attendanceDeviceUser): bool
    {
        return $authUser->can('Delete:AttendanceDeviceUser');
    }

    public function restore(AuthUser $authUser, AttendanceDeviceUser $attendanceDeviceUser): bool
    {
        return $authUser->can('Restore:AttendanceDeviceUser');
    }

    public function forceDelete(AuthUser $authUser, AttendanceDeviceUser $attendanceDeviceUser): bool
    {
        return $authUser->can('ForceDelete:AttendanceDeviceUser');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AttendanceDeviceUser');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AttendanceDeviceUser');
    }

    public function replicate(AuthUser $authUser, AttendanceDeviceUser $attendanceDeviceUser): bool
    {
        return $authUser->can('Replicate:AttendanceDeviceUser');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AttendanceDeviceUser');
    }
}
