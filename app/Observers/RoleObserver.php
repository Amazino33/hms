<?php

namespace App\Observers;

use Illuminate\Support\Facades\Cache;
use App\Services\SidebarCache;
use Spatie\Permission\Models\Role;

class RoleObserver
{
    public function created(Role $role): void
    {
        SidebarCache::clearForAllUsers();

        activity('role')
            ->performedOn($role)
            ->causedBy(auth()->user())
            ->withProperties(['name' => $role->name])
            ->log('Role created: ' . $role->name);
    }

    public function updated(Role $role): void
    {
        // Role rename/metadata changed — clear sidebar caches for everyone with this role
        $role->users()->pluck('id')->each(fn($id) => SidebarCache::clearForUser($id));

        activity('role')
            ->performedOn($role)
            ->causedBy(auth()->user())
            ->withProperties(['attributes' => $role->getChanges(), 'old' => $role->getOriginal()])
            ->log('Role updated: ' . $role->name);
    }

    public function deleted(Role $role): void
    {
        SidebarCache::clearForAllUsers();

        activity('role')
            ->causedBy(auth()->user())
            ->withProperties(['name' => $role->name])
            ->log('Role deleted: ' . $role->name);
    }
}
