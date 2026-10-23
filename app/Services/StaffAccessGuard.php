<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Who may change whose access. Two rules, enforced server-side wherever a
 * role or a credential of another person is changed — never by hiding a
 * form field alone:
 *
 *  1. Only holders of ASSIGN_STAFF_ROLES may give, take or swap roles.
 *  2. Without ASSIGN_STAFF_ROLES nobody may edit, reset the password of, or
 *     reset the PIN of an account holding a protected role (super_admin,
 *     ceo) — the edit form would otherwise be a route to taking one over.
 *
 * A permission rather than a role check, so it can later be granted to
 * the CEO without new code. A null actor in a console run (seeders,
 * artisan commands, tests) is the system itself and passes.
 */
class StaffAccessGuard
{
    public const ASSIGN_STAFF_ROLES = 'assign_staff_roles';

    public const DEACTIVATE_STAFF = 'deactivate_staff';

    public const PROTECTED_ROLES = ['super_admin', 'ceo'];

    public static function canAssignRoles(?User $actor): bool
    {
        if ($actor === null) {
            return app()->runningInConsole();
        }

        return $actor->can(self::ASSIGN_STAFF_ROLES);
    }

    public static function canDeactivate(?User $actor): bool
    {
        return $actor !== null && $actor->can(self::DEACTIVATE_STAFF);
    }

    /**
     * May $actor edit, reset the password of, or reset the PIN of $target?
     */
    public static function canManage(?User $actor, User $target): bool
    {
        if (! $target->hasRole(self::PROTECTED_ROLES)) {
            return true;
        }

        return self::canAssignRoles($actor);
    }

    /**
     * @throws ValidationException
     */
    public static function assertCanAssignRoles(?User $actor): void
    {
        if (! self::canAssignRoles($actor)) {
            throw ValidationException::withMessages([
                'roles' => 'Only the super admin can change staff roles.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertCanManage(?User $actor, User $target): void
    {
        if (! self::canManage($actor, $target)) {
            throw ValidationException::withMessages([
                'user' => "You can't change {$target->name}'s account — only the super admin can.",
            ]);
        }
    }
}
