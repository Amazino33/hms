<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Ingredient;
use Illuminate\Auth\Access\HandlesAuthorization;

class IngredientPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Ingredient');
    }

    public function view(AuthUser $authUser, Ingredient $ingredient): bool
    {
        return $authUser->can('View:Ingredient');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Ingredient');
    }

    public function update(AuthUser $authUser, Ingredient $ingredient): bool
    {
        return $authUser->can('Update:Ingredient');
    }

    public function delete(AuthUser $authUser, Ingredient $ingredient): bool
    {
        return $authUser->can('Delete:Ingredient');
    }

    public function restore(AuthUser $authUser, Ingredient $ingredient): bool
    {
        return $authUser->can('Restore:Ingredient');
    }

    public function forceDelete(AuthUser $authUser, Ingredient $ingredient): bool
    {
        return $authUser->can('ForceDelete:Ingredient');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Ingredient');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Ingredient');
    }

    public function replicate(AuthUser $authUser, Ingredient $ingredient): bool
    {
        return $authUser->can('Replicate:Ingredient');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Ingredient');
    }

}