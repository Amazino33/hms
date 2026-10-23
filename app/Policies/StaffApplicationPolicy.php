<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffApplication;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Viewing follows Shield's ViewAny/View permissions (seeded for
 * super_admin only). Applications are never created, edited or deleted
 * through Filament: /join creates them and StaffApplicationService decides
 * them, behind its own approve_staff_applications check.
 */
class StaffApplicationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StaffApplication');
    }

    public function view(AuthUser $authUser, StaffApplication $staffApplication): bool
    {
        return $authUser->can('View:StaffApplication');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, StaffApplication $staffApplication): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, StaffApplication $staffApplication): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, StaffApplication $staffApplication): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, StaffApplication $staffApplication): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, StaffApplication $staffApplication): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
