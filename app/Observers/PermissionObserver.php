<?php

namespace App\Observers;

use App\Services\SidebarCache;
use Spatie\Permission\Models\Permission;

class PermissionObserver
{
    public function created(Permission $permission): void
    {
        SidebarCache::clearForAllUsers();

        activity('permission')
            ->performedOn($permission)
            ->causedBy(auth()->user())
            ->withProperties(['name' => $permission->name])
            ->log('Permission created: ' . $permission->name);
    }

    public function updated(Permission $permission): void
    {
        // A permission changed — clear all users who hold roles that include this permission
        $roleIds = $permission->roles()->pluck('id');
        foreach ($roleIds as $roleId) {
            \Spatie\Permission\Models\Role::find($roleId)?->users()->pluck('id')->each(fn($id) => SidebarCache::clearForUser($id));
        }

        activity('permission')
            ->performedOn($permission)
            ->causedBy(auth()->user())
            ->withProperties(['attributes' => $permission->getChanges(), 'old' => $permission->getOriginal()])
            ->log('Permission updated: ' . $permission->name);
    }

    public function deleted(Permission $permission): void
    {
        SidebarCache::clearForAllUsers();

        activity('permission')
            ->causedBy(auth()->user())
            ->withProperties(['name' => $permission->name])
            ->log('Permission deleted: ' . $permission->name);
    }
}
