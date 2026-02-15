<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Models\PagePermission;
use App\Observers\OrderObserver;
use App\Observers\UserObserver;
use App\Observers\RoleObserver;
use App\Observers\PermissionObserver;
use App\Observers\PagePermissionObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use App\Services\SidebarCache;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerObservers();

        // Listen to spatie permission attach/detach events and invalidate sidebar cache accordingly
        Event::listen([RoleAttached::class, RoleDetached::class], function ($event) {
            if (isset($event->model) && $event->model instanceof User) {
                SidebarCache::clearForUser($event->model->id);
                return;
            }

            // fallback: clear all user sidebars
            SidebarCache::clearForAllUsers();
        });

        Event::listen([PermissionAttached::class, PermissionDetached::class], function ($event) {
            // If event contains a role, clear cache for users who have that role
            if (isset($event->role) && $event->role instanceof Role) {
                $event->role->users()->pluck('id')->each(fn($id) => SidebarCache::clearForUser($id));
                return;
            }

            SidebarCache::clearForAllUsers();
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    protected function registerObservers(): void
    {
        Order::observe(OrderObserver::class);
        User::observe(UserObserver::class);
        PagePermission::observe(PagePermissionObserver::class);

        // Spatie models
        Role::observe(RoleObserver::class);
        Permission::observe(PermissionObserver::class);
    }
}
