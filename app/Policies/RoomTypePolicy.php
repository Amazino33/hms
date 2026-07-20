<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RoomType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RoomTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RoomType');
    }

    public function view(AuthUser $authUser, RoomType $roomType): bool
    {
        return $authUser->can('View:RoomType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RoomType');
    }

    public function update(AuthUser $authUser, RoomType $roomType): bool
    {
        return $authUser->can('Update:RoomType');
    }

    /**
     * Never actually wired to a delete action anywhere — room types are
     * deactivated, never deleted, so a Room always keeps a valid type
     * reference. Policy method still exists for completeness.
     */
    public function delete(AuthUser $authUser, RoomType $roomType): bool
    {
        return false;
    }
}
