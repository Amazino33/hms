<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The only sanctioned way to put a user into, or take them out of,
 * attendance-only status.
 *
 * attendance_only is exclusive: a user holding it holds nothing else. The
 * role's entire security value is that it grants no panel and no permission,
 * and a second role sitting alongside it would hand back exactly what it was
 * meant to withhold — so the rule is enforced here, server-side, rather than
 * by hiding an option in a form.
 *
 * Converting in either direction always keeps the SAME user record. A cleaner
 * who is later made a waiter is the same person, with the same attendance
 * history and the same device link; creating a second account for them would
 * split both.
 */
class AttendanceOnlyRoleService
{
    public static function role(): Role
    {
        return Role::firstOrCreate(
            ['name' => User::ATTENDANCE_ONLY_ROLE, 'guard_name' => 'web'],
        );
    }

    public static function isAttendanceOnly(User $user): bool
    {
        return $user->hasRole(User::ATTENDANCE_ONLY_ROLE);
    }

    /**
     * Make this user attendance-only, dropping every other role.
     *
     * Credentials are cleared too: an attendance-only user has no way in, and
     * leaving a live password or PIN behind would mean the record still
     * authenticates even though nothing is supposed to.
     */
    public static function makeAttendanceOnly(User $user, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $actor) {
            $previous = $user->roles->pluck('name')->sort()->values()->all();

            $user->syncRoles([self::role()->name]);

            $user->forceFill([
                'password' => null,
                'pin_hash' => null,
                'pin_lookup_hash' => null,
                'pin_set_at' => null,
            ])->save();

            activity('user')
                ->performedOn($user)
                ->causedBy($actor)
                ->withProperties(['previous_roles' => $previous])
                ->log('Converted '.$user->name.' to attendance-only staff');

            return $user->refresh();
        });
    }

    /**
     * Convert back to an app user with real roles, in one transaction so the
     * record is never briefly left holding both attendance_only and a role
     * that grants panel access.
     *
     * @param  array<int, string>  $roles
     *
     * @throws ValidationException
     */
    public static function makeAppUser(User $user, array $roles, ?User $actor = null): User
    {
        $roles = array_values(array_filter($roles, fn ($r) => $r !== User::ATTENDANCE_ONLY_ROLE));

        if ($roles === []) {
            throw ValidationException::withMessages([
                'roles' => 'Give this person at least one role — an app user with no role cannot sign in anywhere.',
            ]);
        }

        return DB::transaction(function () use ($user, $roles, $actor) {
            $user->syncRoles($roles);

            activity('user')
                ->performedOn($user)
                ->causedBy($actor)
                ->withProperties(['roles' => $roles])
                ->log('Converted '.$user->name.' from attendance-only to app user');

            return $user->refresh();
        });
    }

    /**
     * Reject any role set that mixes attendance_only with anything else.
     *
     * @param  array<int, string>  $roleNames
     *
     * @throws ValidationException
     */
    public static function assertExclusive(array $roleNames): void
    {
        $names = array_values(array_unique(array_filter($roleNames)));

        if (! in_array(User::ATTENDANCE_ONLY_ROLE, $names, true)) {
            return;
        }

        if (count($names) > 1) {
            throw ValidationException::withMessages([
                'roles' => 'Attendance-only staff cannot hold any other role. Switch the staff type to "App user" instead.',
            ]);
        }
    }
}
