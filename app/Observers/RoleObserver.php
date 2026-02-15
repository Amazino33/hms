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
    }

    public function updated(Role $role): void
    {
        // Role rename/metadata changed — clear sidebar caches for everyone with this role
        $role->users()->pluck('id')->each(fn($id) => SidebarCache::clearForUser($id));
    }

    public function deleted(Role $role): void
    {
        SidebarCache::clearForAllUsers();
    }
}
