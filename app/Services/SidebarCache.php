<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SidebarCache
{
    public static function cacheKeyFor(int $userId): string
    {
        return 'sidebar_html_user_' . $userId;
    }

    public static function clearForUser(int $userId): void
    {
        Cache::forget(self::cacheKeyFor($userId));
    }

    public static function clearForAllUsers(): void
    {
        User::query()->select('id')->chunk(100, function ($rows) {
            foreach ($rows as $r) {
                Cache::forget(self::cacheKeyFor($r->id));
            }
        });
    }
}
