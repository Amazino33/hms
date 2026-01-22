<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Table;
use Illuminate\Auth\Access\HandlesAuthorization;

class TablePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Table');
    }

    public function view(AuthUser $authUser, Table $table): bool
    {
        return $authUser->can('View:Table');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Table');
    }

    public function update(AuthUser $authUser, Table $table): bool
    {
        return $authUser->can('Update:Table');
    }

    public function delete(AuthUser $authUser, Table $table): bool
    {
        return $authUser->can('Delete:Table');
    }

    public function restore(AuthUser $authUser, Table $table): bool
    {
        return $authUser->can('Restore:Table');
    }

    public function forceDelete(AuthUser $authUser, Table $table): bool
    {
        return $authUser->can('ForceDelete:Table');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Table');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Table');
    }

    public function replicate(AuthUser $authUser, Table $table): bool
    {
        return $authUser->can('Replicate:Table');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Table');
    }

}