<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\StockAdjustment;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockAdjustmentPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockAdjustment');
    }

    public function view(AuthUser $authUser, StockAdjustment $stockAdjustment): bool
    {
        return $authUser->can('View:StockAdjustment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockAdjustment');
    }

    public function update(AuthUser $authUser, StockAdjustment $stockAdjustment): bool
    {
        return $authUser->can('Update:StockAdjustment');
    }

    public function delete(AuthUser $authUser, StockAdjustment $stockAdjustment): bool
    {
        return $authUser->can('Delete:StockAdjustment');
    }

    public function restore(AuthUser $authUser, StockAdjustment $stockAdjustment): bool
    {
        return $authUser->can('Restore:StockAdjustment');
    }

    public function forceDelete(AuthUser $authUser, StockAdjustment $stockAdjustment): bool
    {
        return $authUser->can('ForceDelete:StockAdjustment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StockAdjustment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockAdjustment');
    }

    public function replicate(AuthUser $authUser, StockAdjustment $stockAdjustment): bool
    {
        return $authUser->can('Replicate:StockAdjustment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockAdjustment');
    }

}