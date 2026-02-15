<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UserObserver
{
    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Invalidate per-user sidebar cache so UI changes (name/roles) are reflected.
        Cache::forget('sidebar_html_user_' . $user->id);
    }

    public function deleted(User $user): void
    {
        Cache::forget('sidebar_html_user_' . $user->id);
    }

    public function created(User $user): void
    {
        Cache::forget('sidebar_html_user_' . $user->id);
    }
}
