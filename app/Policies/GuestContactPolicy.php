<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GuestContact;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GuestContactPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GuestContact');
    }

    public function view(AuthUser $authUser, GuestContact $guestContact): bool
    {
        return $authUser->can('View:GuestContact');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GuestContact');
    }

    public function update(AuthUser $authUser, GuestContact $guestContact): bool
    {
        return $authUser->can('Update:GuestContact');
    }

    public function delete(AuthUser $authUser, GuestContact $guestContact): bool
    {
        return $authUser->can('Delete:GuestContact');
    }

    public function restore(AuthUser $authUser, GuestContact $guestContact): bool
    {
        return $authUser->can('Restore:GuestContact');
    }

    public function forceDelete(AuthUser $authUser, GuestContact $guestContact): bool
    {
        return $authUser->can('ForceDelete:GuestContact');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GuestContact');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GuestContact');
    }

    public function replicate(AuthUser $authUser, GuestContact $guestContact): bool
    {
        return $authUser->can('Replicate:GuestContact');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GuestContact');
    }
}
