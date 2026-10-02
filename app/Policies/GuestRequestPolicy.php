<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GuestRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GuestRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GuestRequest');
    }

    public function view(AuthUser $authUser, GuestRequest $guestRequest): bool
    {
        return $authUser->can('View:GuestRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GuestRequest');
    }

    public function update(AuthUser $authUser, GuestRequest $guestRequest): bool
    {
        return $authUser->can('Update:GuestRequest');
    }

    public function delete(AuthUser $authUser, GuestRequest $guestRequest): bool
    {
        return $authUser->can('Delete:GuestRequest');
    }

    public function restore(AuthUser $authUser, GuestRequest $guestRequest): bool
    {
        return $authUser->can('Restore:GuestRequest');
    }

    public function forceDelete(AuthUser $authUser, GuestRequest $guestRequest): bool
    {
        return $authUser->can('ForceDelete:GuestRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GuestRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GuestRequest');
    }

    public function replicate(AuthUser $authUser, GuestRequest $guestRequest): bool
    {
        return $authUser->can('Replicate:GuestRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GuestRequest');
    }
}
