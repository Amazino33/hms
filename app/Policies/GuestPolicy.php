<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Guest;
use Illuminate\Auth\Access\HandlesAuthorization;

class GuestPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Guest');
    }

    public function view(AuthUser $authUser, Guest $guest): bool
    {
        return $authUser->can('View:Guest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Guest');
    }

    public function update(AuthUser $authUser, Guest $guest): bool
    {
        return $authUser->can('Update:Guest');
    }

    public function delete(AuthUser $authUser, Guest $guest): bool
    {
        return $authUser->can('Delete:Guest');
    }

    public function restore(AuthUser $authUser, Guest $guest): bool
    {
        return $authUser->can('Restore:Guest');
    }

    public function forceDelete(AuthUser $authUser, Guest $guest): bool
    {
        return $authUser->can('ForceDelete:Guest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Guest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Guest');
    }

    public function replicate(AuthUser $authUser, Guest $guest): bool
    {
        return $authUser->can('Replicate:Guest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Guest');
    }

}