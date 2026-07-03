<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\StaffDebt;
use Illuminate\Auth\Access\HandlesAuthorization;

class StaffDebtPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StaffDebt');
    }

    public function view(AuthUser $authUser, StaffDebt $staffDebt): bool
    {
        return $authUser->can('View:StaffDebt');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StaffDebt');
    }

    public function update(AuthUser $authUser, StaffDebt $staffDebt): bool
    {
        return $authUser->can('Update:StaffDebt');
    }

    public function delete(AuthUser $authUser, StaffDebt $staffDebt): bool
    {
        return $authUser->can('Delete:StaffDebt');
    }

    public function restore(AuthUser $authUser, StaffDebt $staffDebt): bool
    {
        return $authUser->can('Restore:StaffDebt');
    }

    public function forceDelete(AuthUser $authUser, StaffDebt $staffDebt): bool
    {
        return $authUser->can('ForceDelete:StaffDebt');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StaffDebt');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StaffDebt');
    }

    public function replicate(AuthUser $authUser, StaffDebt $staffDebt): bool
    {
        return $authUser->can('Replicate:StaffDebt');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StaffDebt');
    }

}