<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChipGroup;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ChipGroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ChipGroup');
    }

    public function view(AuthUser $authUser, ChipGroup $chipGroup): bool
    {
        return $authUser->can('View:ChipGroup');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ChipGroup');
    }

    public function update(AuthUser $authUser, ChipGroup $chipGroup): bool
    {
        return $authUser->can('Update:ChipGroup');
    }

    public function delete(AuthUser $authUser, ChipGroup $chipGroup): bool
    {
        return $authUser->can('Delete:ChipGroup');
    }

    public function restore(AuthUser $authUser, ChipGroup $chipGroup): bool
    {
        return $authUser->can('Restore:ChipGroup');
    }

    public function forceDelete(AuthUser $authUser, ChipGroup $chipGroup): bool
    {
        return $authUser->can('ForceDelete:ChipGroup');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ChipGroup');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ChipGroup');
    }

    public function replicate(AuthUser $authUser, ChipGroup $chipGroup): bool
    {
        return $authUser->can('Replicate:ChipGroup');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ChipGroup');
    }
}
