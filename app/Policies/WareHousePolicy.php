<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\WareHouse;
use Illuminate\Auth\Access\HandlesAuthorization;

class WareHousePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WareHouse');
    }

    public function view(AuthUser $authUser, WareHouse $wareHouse): bool
    {
        return $authUser->can('View:WareHouse');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WareHouse');
    }

    public function update(AuthUser $authUser, WareHouse $wareHouse): bool
    {
        return $authUser->can('Update:WareHouse');
    }

    public function delete(AuthUser $authUser, WareHouse $wareHouse): bool
    {
        return $authUser->can('Delete:WareHouse');
    }

    public function restore(AuthUser $authUser, WareHouse $wareHouse): bool
    {
        return $authUser->can('Restore:WareHouse');
    }

    public function forceDelete(AuthUser $authUser, WareHouse $wareHouse): bool
    {
        return $authUser->can('ForceDelete:WareHouse');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WareHouse');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WareHouse');
    }

    public function replicate(AuthUser $authUser, WareHouse $wareHouse): bool
    {
        return $authUser->can('Replicate:WareHouse');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WareHouse');
    }

}