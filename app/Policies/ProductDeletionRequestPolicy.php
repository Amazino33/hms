<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductDeletionRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductDeletionRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductDeletionRequest');
    }

    public function view(AuthUser $authUser, ProductDeletionRequest $productDeletionRequest): bool
    {
        return $authUser->can('View:ProductDeletionRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductDeletionRequest');
    }

    public function update(AuthUser $authUser, ProductDeletionRequest $productDeletionRequest): bool
    {
        return $authUser->can('Update:ProductDeletionRequest');
    }

    public function delete(AuthUser $authUser, ProductDeletionRequest $productDeletionRequest): bool
    {
        return $authUser->can('Delete:ProductDeletionRequest');
    }

    public function restore(AuthUser $authUser, ProductDeletionRequest $productDeletionRequest): bool
    {
        return $authUser->can('Restore:ProductDeletionRequest');
    }

    public function forceDelete(AuthUser $authUser, ProductDeletionRequest $productDeletionRequest): bool
    {
        return $authUser->can('ForceDelete:ProductDeletionRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductDeletionRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductDeletionRequest');
    }

    public function replicate(AuthUser $authUser, ProductDeletionRequest $productDeletionRequest): bool
    {
        return $authUser->can('Replicate:ProductDeletionRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductDeletionRequest');
    }
}
