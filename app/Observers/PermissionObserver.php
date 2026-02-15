<?php

namespace App\Observers;

use App\Services\SidebarCache;
use Spatie\Permission\Models\Permission;

class PermissionObserver
{
    public function created(Permission $permission): void
    {
        SidebarCache::clearForAllUsers();
    }

    public function updated(Permission $permission): void
    {
        // A permission changed — clear all users who hold roles that include this permission
        $roleIds = $permission->roles()->pluck('id');
        foreach ($roleIds as $roleId) {
            \Spatie\Permission\Models\Role::find($roleId)?->users()->pluck('id')->each(fn($id) => SidebarCache::clearForUser($id));
        }
    }

    public function deleted(Permission $permission): void
    {
        SidebarCache::clearForAllUsers();
    }
}
