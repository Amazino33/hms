<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ProductionOrder;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductionOrderPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductionOrder');
    }

    public function view(AuthUser $authUser, ProductionOrder $productionOrder): bool
    {
        return $authUser->can('View:ProductionOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductionOrder');
    }

    public function update(AuthUser $authUser, ProductionOrder $productionOrder): bool
    {
        return $authUser->can('Update:ProductionOrder');
    }

    public function delete(AuthUser $authUser, ProductionOrder $productionOrder): bool
    {
        return $authUser->can('Delete:ProductionOrder');
    }

    public function restore(AuthUser $authUser, ProductionOrder $productionOrder): bool
    {
        return $authUser->can('Restore:ProductionOrder');
    }

    public function forceDelete(AuthUser $authUser, ProductionOrder $productionOrder): bool
    {
        return $authUser->can('ForceDelete:ProductionOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductionOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductionOrder');
    }

    public function replicate(AuthUser $authUser, ProductionOrder $productionOrder): bool
    {
        return $authUser->can('Replicate:ProductionOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductionOrder');
    }

}