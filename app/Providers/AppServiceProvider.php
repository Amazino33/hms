<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\StaffDebt;
use App\Models\User;
use App\Models\PagePermission;
use App\Observers\OrderObserver;
use App\Observers\StaffDebtObserver;
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
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every ->danger() notification across the app — most call sites
        // still use raw Notification::make() rather than the shared
        // UserFeedback service — resolves through this binding first, so
        // LoggingNotification::send() can also mirror it into the System
        // Error Log without editing every one of those call sites.
        $this->app->bind(\Filament\Notifications\Notification::class, function ($app, array $parameters) {
            return new \App\Support\LoggingNotification($parameters['id']);
        });
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
            if ($event->model instanceof User) {
                SidebarCache::clearForUser($event->model->id);
            } else {
                // fallback: clear all user sidebars
                SidebarCache::clearForAllUsers();
            }

            $verb = $event instanceof RoleAttached ? 'attached to' : 'detached from';
            $subject = $event->model instanceof User ? ($event->model->name ?? $event->model->email) : get_class($event->model) . '#' . $event->model->id;

            activity('role')
                ->performedOn($event->model)
                ->causedBy(auth()->user())
                ->withProperties(['rolesOrIds' => self::describeRolesOrPermissions($event->rolesOrIds)])
                ->log("Role(s) {$verb} {$subject}");
        });

        Event::listen([PermissionAttached::class, PermissionDetached::class], function ($event) {
            // The model a permission is attached to/detached from is usually a
            // Role (e.g. $role->givePermissionTo(...)) but Spatie allows giving
            // permissions directly to a User too — handle both.
            if ($event->model instanceof Role) {
                $event->model->users()->pluck('id')->each(fn($id) => SidebarCache::clearForUser($id));
            } elseif ($event->model instanceof User) {
                SidebarCache::clearForUser($event->model->id);
            } else {
                SidebarCache::clearForAllUsers();
            }

            $verb = $event instanceof PermissionAttached ? 'attached to' : 'detached from';
            $subject = $event->model instanceof Role
                ? 'role ' . $event->model->name
                : (($event->model->name ?? $event->model->email) ?? get_class($event->model) . '#' . $event->model->id);

            activity('permission')
                ->performedOn($event->model)
                ->causedBy(auth()->user())
                ->withProperties(['permissionsOrIds' => self::describeRolesOrPermissions($event->permissionsOrIds)])
                ->log("Permission(s) {$verb} {$subject}");
        });

        // Auth events — logins, failed logins, logouts
        Event::listen(Login::class, function (Login $event) {
            activity('auth')
                ->causedBy($event->user)
                ->withProperties(['guard' => $event->guard])
                ->log('Login: ' . ($event->user->email ?? $event->user->getAuthIdentifier()));
        });

        Event::listen(Failed::class, function (Failed $event) {
            activity('auth')
                ->causedBy($event->user)
                ->withProperties(['guard' => $event->guard, 'email' => $event->credentials['email'] ?? null])
                ->log('Failed login attempt' . (isset($event->credentials['email']) ? ' for ' . $event->credentials['email'] : ''));
        });

        Event::listen(Logout::class, function (Logout $event) {
            activity('auth')
                ->causedBy($event->user)
                ->withProperties(['guard' => $event->guard])
                ->log('Logout: ' . ($event->user?->email ?? 'unknown'));
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

    /**
     * Spatie's Role/PermissionAttached/Detached events pass rolesOrIds /
     * permissionsOrIds as any of: an id, an array of ids, a Role/Permission
     * model, an array of models, or a Collection — normalize whatever shows
     * up into something readable for the activity log property.
     */
    protected static function describeRolesOrPermissions(mixed $value): array
    {
        $items = $value instanceof \Illuminate\Support\Collection ? $value->all() : (is_array($value) ? $value : [$value]);

        return collect($items)->map(function ($item) {
            if (is_object($item) && method_exists($item, 'getAttribute')) {
                return $item->name ?? $item->getKey();
            }

            return $item;
        })->all();
    }

    protected function registerObservers(): void
    {
        Order::observe(OrderObserver::class);
        User::observe(UserObserver::class);
        PagePermission::observe(PagePermissionObserver::class);
        StaffDebt::observe(StaffDebtObserver::class);

        // Spatie models
        Role::observe(RoleObserver::class);
        Permission::observe(PermissionObserver::class);
    }
}
