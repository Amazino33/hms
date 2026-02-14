<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\MenuItem;
use Illuminate\Auth\Access\HandlesAuthorization;

class MenuItemPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MenuItem');
    }

    public function view(AuthUser $authUser, MenuItem $menuItem): bool
    {
        return $authUser->can('View:MenuItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MenuItem');
    }

    public function update(AuthUser $authUser, MenuItem $menuItem): bool
    {
        return $authUser->can('Update:MenuItem');
    }

    public function delete(AuthUser $authUser, MenuItem $menuItem): bool
    {
        return $authUser->can('Delete:MenuItem');
    }

    public function restore(AuthUser $authUser, MenuItem $menuItem): bool
    {
        return $authUser->can('Restore:MenuItem');
    }

    public function forceDelete(AuthUser $authUser, MenuItem $menuItem): bool
    {
        return $authUser->can('ForceDelete:MenuItem');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MenuItem');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MenuItem');
    }

    public function replicate(AuthUser $authUser, MenuItem $menuItem): bool
    {
        return $authUser->can('Replicate:MenuItem');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MenuItem');
    }

}