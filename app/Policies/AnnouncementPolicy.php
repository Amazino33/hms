<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Announcement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Mirrors the shape Shield generates for every other resource in this
 * panel, so `shield:generate` on deploy produces exactly these
 * permissions and nothing drifts.
 *
 * Written by hand rather than generated because this resource needs a
 * policy from its very first deploy: a Filament Resource with no policy
 * is open to every authenticated panel user, and this one puts a blocking
 * modal on every screen in the building.
 *
 * Note the deliberate asymmetry with the Notices page: reading your own
 * notices is gated by PagePermission, writing one is gated here. They are
 * separate grants on purpose.
 */
class AnnouncementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Announcement');
    }

    public function view(AuthUser $authUser, Announcement $announcement): bool
    {
        return $authUser->can('View:Announcement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Announcement');
    }

    public function update(AuthUser $authUser, Announcement $announcement): bool
    {
        return $authUser->can('Update:Announcement');
    }

    public function delete(AuthUser $authUser, Announcement $announcement): bool
    {
        return $authUser->can('Delete:Announcement');
    }

    public function restore(AuthUser $authUser, Announcement $announcement): bool
    {
        return $authUser->can('Restore:Announcement');
    }

    public function forceDelete(AuthUser $authUser, Announcement $announcement): bool
    {
        return $authUser->can('ForceDelete:Announcement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Announcement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Announcement');
    }

    public function replicate(AuthUser $authUser, Announcement $announcement): bool
    {
        return $authUser->can('Replicate:Announcement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Announcement');
    }
}
