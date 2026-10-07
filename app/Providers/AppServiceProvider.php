<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\PagePermission;
use App\Models\StaffDebt;
use App\Models\User;
use App\Observers\OrderObserver;
use App\Observers\PagePermissionObserver;
use App\Observers\PermissionObserver;
use App\Observers\RoleObserver;
use App\Observers\StaffDebtObserver;
use App\Observers\UserObserver;
use App\Services\SidebarCache;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

        $this->registerGuestRateLimiters();
        $this->forgetGuestMenuCacheOnMenuChanges();

        // Listen to spatie permission attach/detach events and invalidate sidebar cache accordingly
        Event::listen([RoleAttached::class, RoleDetached::class], function ($event) {
            if ($event->model instanceof User) {
                SidebarCache::clearForUser($event->model->id);
            } else {
                // fallback: clear all user sidebars
                SidebarCache::clearForAllUsers();
            }

            $verb = $event instanceof RoleAttached ? 'attached to' : 'detached from';
            $subject = $event->model instanceof User ? ($event->model->name ?? $event->model->email) : get_class($event->model).'#'.$event->model->id;

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
                $event->model->users()->pluck('id')->each(fn ($id) => SidebarCache::clearForUser($id));
            } elseif ($event->model instanceof User) {
                SidebarCache::clearForUser($event->model->id);
            } else {
                SidebarCache::clearForAllUsers();
            }

            $verb = $event instanceof PermissionAttached ? 'attached to' : 'detached from';
            $subject = $event->model instanceof Role
                ? 'role '.$event->model->name
                : (($event->model->name ?? $event->model->email) ?? get_class($event->model).'#'.$event->model->id);

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
                ->log('Login: '.($event->user->email ?? $event->user->getAuthIdentifier()));
        });

        Event::listen(Failed::class, function (Failed $event) {
            activity('auth')
                ->causedBy($event->user)
                ->withProperties(['guard' => $event->guard, 'email' => $event->credentials['email'] ?? null])
                ->log('Failed login attempt'.(isset($event->credentials['email']) ? ' for '.$event->credentials['email'] : ''));
        });

        Event::listen(Logout::class, function (Logout $event) {
            activity('auth')
                ->causedBy($event->user)
                ->withProperties(['guard' => $event->guard])
                ->log('Logout: '.($event->user?->email ?? 'unknown'));
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Timestamps are stored in UTC but staff read them in Lagos wall
        // clock. Without these two lines every screen in the app renders an
        // hour early, and anything logged in the first hour of a Lagos day
        // shows under the previous date. Storage deliberately stays UTC —
        // this is a display-layer conversion only. See App\Support\VenueTime.
        VenueTime::registerMacros();

        // Covers every Filament ->dateTime() column, infolist entry and
        // date-time picker in both panels at once (they resolve their zone
        // through this manager). Plain ->date() columns do NOT consult it —
        // those pass the zone explicitly at the call site.
        FilamentTimezone::set(VenueTime::TIMEZONE);

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

    /**
     * Guest QR pages (D10). Keyed by the phone's `selum_gd` device cookie,
     * with a generous per-IP ceiling on top, because every guest on the
     * venue Wi-Fi shares one public IP. A first visit (no cookie yet) is
     * keyed by IP. Each limiter's IP ceiling is counted separately.
     */
    protected function registerGuestRateLimiters(): void
    {
        $device = function (\Illuminate\Http\Request $request): string {
            $cookie = (string) $request->cookies->get(\App\Http\Middleware\EnsureGuestDevice::COOKIE, '');

            return preg_match('/^[A-Za-z0-9]{32}$/', $cookie) ? 'dev:'.$cookie : 'ip:'.$request->ip();
        };

        $limit = \Illuminate\Cache\RateLimiting\Limit::class;

        \Illuminate\Support\Facades\RateLimiter::for('guest-page', fn ($request) => [
            $limit::perMinute(60)->by('page:'.$device($request)),
            $limit::perMinute(1200)->by('page-ip:'.$request->ip()),
        ]);

        \Illuminate\Support\Facades\RateLimiter::for('guest-poll', fn ($request) => [
            $limit::perMinute(30)->by('poll:'.$device($request)),
            $limit::perMinute(3000)->by('poll-ip:'.$request->ip()),
        ]);

        \Illuminate\Support\Facades\RateLimiter::for('guest-submit', fn ($request) => [
            $limit::perMinutes(10, 6)->by('submit:'.$device($request)),
            // A room's sticker: 20 an hour (Phase 5); a table's: 40.
            $limit::perHour(\App\Services\Guest\QrTokens::resolve((string) $request->route('token')) instanceof \App\Models\Room ? 20 : 40)
                ->by('submit-token:'.$request->route('token')),
            $limit::perMinute(300)->by('submit-ip:'.$request->ip()),
        ]);

        \Illuminate\Support\Facades\RateLimiter::for('guest-cancel', fn ($request) => [
            $limit::perMinute(20)->by('cancel:'.$device($request)),
            $limit::perMinute(600)->by('cancel-ip:'.$request->ip()),
        ]);

        // Phase 4: "I've paid" claims, and Call waiter (on top of the
        // service's own one-call-per-2-minutes rule).
        \Illuminate\Support\Facades\RateLimiter::for('guest-claim', fn ($request) => [
            $limit::perMinutes(10, 5)->by('claim:'.$device($request)),
            $limit::perMinute(300)->by('claim-ip:'.$request->ip()),
        ]);

        \Illuminate\Support\Facades\RateLimiter::for('guest-call', fn ($request) => [
            $limit::perHour(6)->by('call:'.$device($request)),
            $limit::perMinute(300)->by('call-ip:'.$request->ip()),
        ]);
    }

    /**
     * D11: the guest menu's structure is cached 5 minutes and dropped the
     * moment anything on it changes. Sold-out flips drop the 30-second
     * availability cache too, so the kitchen's toggle shows straight away.
     */
    protected function forgetGuestMenuCacheOnMenuChanges(): void
    {
        $forget = function () {
            \App\Services\Guest\GuestMenuService::forgetMenuCache();
            \Illuminate\Support\Facades\Cache::forget(\App\Services\Guest\GuestMenuService::UNAVAILABLE_CACHE_KEY);
        };

        foreach ([\App\Models\MenuItem::class, \App\Models\Product::class, \App\Models\Category::class, \App\Models\ChipGroup::class, \App\Models\ChipOption::class] as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }
    }

    protected function registerObservers(): void
    {
        Order::observe(OrderObserver::class);
        User::observe(UserObserver::class);
        PagePermission::observe(PagePermissionObserver::class);
        StaffDebt::observe(StaffDebtObserver::class);

        // Guest room ordering (Phase 5): listen, never edit — kitchen Mark
        // Ready queues room food for a porter; checkout cancels requests
        // that never became orders.
        Order::observe(\App\Observers\GuestRoomOrderObserver::class);
        \App\Models\Booking::observe(\App\Observers\GuestStayObserver::class);

        // Mirrors the terminal's own name store into attendance_device_users
        // one way. ZKTecoController and hms:set-machine-name both write
        // biometric_enrollments through Eloquent, so this fires for both;
        // attendance:reconcile-device-users is the hourly net under anything
        // that does not.
        \App\Models\BiometricEnrollment::observe(\App\Observers\BiometricEnrollmentObserver::class);

        // Spatie models
        Role::observe(RoleObserver::class);
        Permission::observe(PermissionObserver::class);
    }
}
