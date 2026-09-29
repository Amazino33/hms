<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DailyAttendance;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Gates the Daily Attendance table.
 *
 * DailyAttendance is a database view over attendance_logs, so it carries no
 * permissions of its own — it reads the same rows AttendanceLog does and is
 * deliberately checked against the same ViewAny:AttendanceLog permission.
 * Without this class the resource would be ungoverned: Laravel resolves
 * policies by model class, and switching the resource's $model from
 * AttendanceLog to the view silently took it out from behind
 * AttendanceLogPolicy and put payroll data back in front of every role.
 *
 * Everything below view is false on purpose. A view has no writable rows;
 * the header CreateAction that Filament scaffolds would fail at the database
 * even if someone were permitted to press it.
 */
class DailyAttendancePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AttendanceLog');
    }

    public function view(AuthUser $authUser, DailyAttendance $dailyAttendance): bool
    {
        return $authUser->can('View:AttendanceLog');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, DailyAttendance $dailyAttendance): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, DailyAttendance $dailyAttendance): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
