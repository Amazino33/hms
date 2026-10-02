<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GuestDeliveryRefusal;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GuestDeliveryRefusalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GuestDeliveryRefusal');
    }

    public function view(AuthUser $authUser, GuestDeliveryRefusal $guestDeliveryRefusal): bool
    {
        return $authUser->can('View:GuestDeliveryRefusal');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GuestDeliveryRefusal');
    }

    public function update(AuthUser $authUser, GuestDeliveryRefusal $guestDeliveryRefusal): bool
    {
        return $authUser->can('Update:GuestDeliveryRefusal');
    }

    public function delete(AuthUser $authUser, GuestDeliveryRefusal $guestDeliveryRefusal): bool
    {
        return $authUser->can('Delete:GuestDeliveryRefusal');
    }

    public function restore(AuthUser $authUser, GuestDeliveryRefusal $guestDeliveryRefusal): bool
    {
        return $authUser->can('Restore:GuestDeliveryRefusal');
    }

    public function forceDelete(AuthUser $authUser, GuestDeliveryRefusal $guestDeliveryRefusal): bool
    {
        return $authUser->can('ForceDelete:GuestDeliveryRefusal');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GuestDeliveryRefusal');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GuestDeliveryRefusal');
    }

    public function replicate(AuthUser $authUser, GuestDeliveryRefusal $guestDeliveryRefusal): bool
    {
        return $authUser->can('Replicate:GuestDeliveryRefusal');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GuestDeliveryRefusal');
    }
}
