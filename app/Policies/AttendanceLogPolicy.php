<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceLog;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AttendanceLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AttendanceLog');
    }

    public function view(AuthUser $authUser, AttendanceLog $attendanceLog): bool
    {
        return $authUser->can('View:AttendanceLog');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AttendanceLog');
    }

    public function update(AuthUser $authUser, AttendanceLog $attendanceLog): bool
    {
        return $authUser->can('Update:AttendanceLog');
    }

    public function delete(AuthUser $authUser, AttendanceLog $attendanceLog): bool
    {
        return $authUser->can('Delete:AttendanceLog');
    }

    public function restore(AuthUser $authUser, AttendanceLog $attendanceLog): bool
    {
        return $authUser->can('Restore:AttendanceLog');
    }

    public function forceDelete(AuthUser $authUser, AttendanceLog $attendanceLog): bool
    {
        return $authUser->can('ForceDelete:AttendanceLog');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AttendanceLog');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AttendanceLog');
    }

    public function replicate(AuthUser $authUser, AttendanceLog $attendanceLog): bool
    {
        return $authUser->can('Replicate:AttendanceLog');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AttendanceLog');
    }
}
