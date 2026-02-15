<?php

namespace App\Observers;

use App\Models\PagePermission;
use App\Services\SidebarCache;

class PagePermissionObserver
{
    public function created(PagePermission $model): void
    {
        SidebarCache::clearForAllUsers();
    }

    public function updated(PagePermission $model): void
    {
        SidebarCache::clearForAllUsers();
    }

    public function deleted(PagePermission $model): void
    {
        SidebarCache::clearForAllUsers();
    }
}
