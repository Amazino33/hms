<?php

namespace App\Providers\Filament;

use App\Filament\Resources\Ingredients\IngredientResource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->globalSearch(false)
            ->databaseNotifications()
            ->databaseNotificationsPolling('5s')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
                \App\Filament\Pages\StorekeeperTransfers::class,
                \App\Filament\Pages\ReceiveTransfers::class,
                \App\Filament\Pages\QuickInventoryUpdate::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                \App\Filament\Widgets\StaffCashSummary::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->resources([
                IngredientResource::class,
            ]);
    }

    public function boot()
    {
        // Register PWA meta tags and service worker
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn() => Blade::render(<<<'HTML'
            <!-- PWA Meta Tags -->
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
            <link rel="manifest" href="/site.webmanifest">
            <meta name="theme-color" content="#1f2937">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="default">
            <meta name="apple-mobile-web-app-title" content="HMS">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="msapplication-TileColor" content="#1f2937">
            <meta name="msapplication-config" content="/browserconfig.xml">
            
            <!-- Load PWA Service Worker -->
            @vite(['resources/js/app.js'])
            HTML)
        );

        // Register mobile sidebar close button
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_START,
            fn() => Blade::render(<<<'HTML'
            <div class="lg:hidden absolute z-50" style="top: 1rem; right: 1rem;">
                <button
                    x-data="{}"
                    x-on:click="$store.sidebar.close()"
                    class="flex items-center justify-center w-8 h-8 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white rounded-full transition-colors shadow-sm"
                    title="Close Menu"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            HTML)
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn() => Blade::render(<<<'HTML'
            <audio id="notification-sound" src="/sounds/notification.wav" preload="auto"></audio>

            <script>
                let notificationCount = 0;
                let audioReady = false;

                // Auto-unlock audio on first user interaction
                document.addEventListener('click', function() {
                    if (!audioReady) {
                        const audio = document.getElementById('notification-sound');
                        audio.play().then(() => {
                            audio.pause();
                            audio.currentTime = 0;
                            audioReady = true;
                            console.log('🔊 Notification sound ready');
                        }).catch(e => console.log('Audio unlock failed:', e));
                    }
                }, { once: true });

                // Monitor notification badge for changes
                setInterval(() => {
                    // Look specifically for the notification icon button's badge
                    const notificationButton = document.querySelector('[data-action="open-notifications"]');
                    const badge = notificationButton ? notificationButton.querySelector('.fi-icon-btn-badge') : null;
                    
                    if (badge) {
                        const currentCount = parseInt(badge.textContent) || 0;
                        
                        // Play sound when count increases (skip initial count)
                        if (currentCount > notificationCount && notificationCount > 0 && audioReady) {
                            const audio = document.getElementById('notification-sound');
                            audio.currentTime = 0;
                            audio.play().catch(err => console.error('Sound play failed:', err));
                        }
                        
                        notificationCount = currentCount;
                    }
                }, 2000);
            </script>

            <!-- Shift Manager Component -->
            @livewire('shift-manager')

            <!-- PWA Install Component -->
            @livewire('pwa-install')
        HTML)
        );

        // Add shift management menu item
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn() => Blade::render(<<<'HTML'
            <li wire:poll.10s>
                <a href="#"
                   wire:click.prevent="$dispatch('open-shift-modal')"
                   class="relative flex items-center justify-center px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded-md transition-colors cursor-pointer touch-manipulation"
                   title="Shift Management"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    @if(auth()->user() && auth()->user()->currentShift())
                        <div class="absolute -top-1 -right-1 w-3 h-3 bg-green-500 rounded-full animate-pulse border border-white dark:border-gray-800"></div>
                    @endif
                </a>
            </li>
        HTML)
        );

        // 1. Force the URL generator to use HTTPS
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');

            // 2. THE NUCLEAR FIX: Manually set the HTTPS server flag
            // This tricks Laravel into thinking the connection is secure
            $this->app['request']->server->set('HTTPS', 'on');
        }

        // 3. Your Super Admin Gate (Keep this!)
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });

        // Register the policies
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }
}
