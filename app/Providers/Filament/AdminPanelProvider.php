<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->spa()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->globalSearch(false)
            ->databaseNotifications()
            ->databaseNotificationsPolling('5s')
            ->colors([
                'primary' => '#E5353A',
            ])
            ->userMenuItems([
                // The Kiosk PIN settings page lives outside the Filament
                // panel's own route/navigation structure (it's a plain Volt
                // settings page shared with Profile/Password/Appearance),
                // so without this the panel's user menu has no way to reach
                // it at all — every staff member would need to be told a
                // URL nobody could otherwise discover.
                'pin' => \Filament\Navigation\MenuItem::make()
                    ->label('Kiosk PIN')
                    ->icon('heroicon-o-key')
                    ->url(fn () => route('pin.edit')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
                \App\Filament\Pages\StorekeeperTransfers::class,
                \App\Filament\Pages\ReceiveTransfers::class,
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
            ]);
    }

    public function boot(): void
    {
        // ─── Body End: Global Livewire request-failure handler ───────────────────
        // Same partial the kiosk layout includes — one script, one behavior,
        // registered once per layout rather than left to each component's own
        // (frequently missing) error handling. This is what actually fixes a
        // page going fully unresponsive on a 419/500/dropped connection.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('partials.livewire-failure-handler')->render()
        );

        // ─── Sidebar: Mobile close button ────────────────────────────────────────
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_START,
            fn () => Blade::render(<<<'HTML'
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

        // ─── Head: Styles (progress bar, x-cloak, hidden badge) ─────────────────
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn () => Blade::render(<<<'HTML'
                <style>
                    /* Hide notification bell badge count */
                    .fi-topbar-database-notifications-btn .fi-icon-btn-badge {
                        display: none !important;
                    }

                    /* Hide Alpine elements before JS loads */
                    [x-cloak] { display: none !important; }

                    /* SPA navigation progress bar */
                    #fi-nprogress {
                        position: fixed;
                        top: 0; left: 0;
                        width: 0%;
                        height: 3px;
                        background: #E5353A;
                        z-index: 99999;
                        transition: width 0.15s ease;
                        border-radius: 0 2px 2px 0;
                        box-shadow: 0 0 8px rgba(229,53,58,0.6);
                        pointer-events: none;
                        opacity: 0;
                    }
                </style>
                <div id="fi-nprogress"></div>
            HTML)
        );

        // ─── Head: PWA capture + Service Worker + Progress bar JS ────────────────
        // Placed in HEAD so beforeinstallprompt is captured as early as possible,
        // before Alpine initialises and before Livewire's SPA router takes over.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn () => Blade::render(<<<'HTML'
                <script>
                    (function () {
                        // ── PWA Install Prompt ──────────────────────────────────
                        window.pwaDeferredPrompt = null;

                        window.addEventListener('beforeinstallprompt', function (e) {
                            e.preventDefault();
                            window.pwaDeferredPrompt = e;
                            window.dispatchEvent(new CustomEvent('pwa-ready'));
                            console.log('✅ PWA install prompt captured');
                        });

                        window.addEventListener('appinstalled', function () {
                            window.pwaDeferredPrompt = null;
                            window.dispatchEvent(new CustomEvent('pwa-installed'));
                            console.log('✅ PWA app installed');
                        });

                        // ── Service Worker (registered once) ───────────────────
                        if ('serviceWorker' in navigator) {
                            window.addEventListener('load', function () {
                                navigator.serviceWorker.register('/sw.js')
                                    .then(function (reg) {
                                        // Auto-update check every hour
                                        setInterval(function () { reg.update(); }, 60 * 60 * 1000);
                                        console.log('✅ Service Worker registered:', reg.scope);
                                    })
                                    .catch(function (err) {
                                        console.error('❌ Service Worker registration failed:', err);
                                    });
                            });
                        }

                        // ── SPA Navigation Progress Bar ────────────────────────
                        function bar() { return document.getElementById('fi-nprogress'); }

                        document.addEventListener('click', function (e) {
                            var link = e.target.closest('a[href]');
                            if (!link) return;
                            var href = link.getAttribute('href');
                            if (!href || href.startsWith('#') || href.startsWith('javascript') || link.target === '_blank') return;
                            var b = bar();
                            if (b) { b.style.opacity = '1'; b.style.width = '60%'; }
                        });

                        window.addEventListener('load', function () {
                            var b = bar();
                            if (b && b.style.width !== '0%') {
                                b.style.width = '100%';
                                setTimeout(function () { b.style.opacity = '0'; b.style.width = '0%'; }, 250);
                            }
                        });

                        document.addEventListener('livewire:navigate', function () {
                            var b = bar();
                            if (b) { b.style.opacity = '1'; b.style.width = '65%'; }
                        });

                        document.addEventListener('livewire:navigated', function () {
                            var b = bar();
                            if (b) {
                                b.style.width = '100%';
                                setTimeout(function () { b.style.opacity = '0'; b.style.width = '0%'; }, 250);
                            }
                        });
                    })();
                </script>
            HTML)
        );

        // ─── Topbar: PWA Install button ───────────────────────────────────────────
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn (): string => Blade::render(<<<'HTML'
                <div
                    x-cloak
                    x-data="{
                        showBtn: false,
                        init() {
                            // Prompt may have already fired before Alpine loaded
                            if (window.pwaDeferredPrompt) {
                                this.showBtn = true;
                            }
                            // Hide if already running as installed PWA
                            if (window.matchMedia('(display-mode: standalone)').matches) {
                                this.showBtn = false;
                                return;
                            }
                            window.addEventListener('pwa-ready', () => {
                                this.showBtn = true;
                            });
                            window.addEventListener('pwa-installed', () => {
                                this.showBtn = false;
                            });
                        },
                        async install() {
                            if (!window.pwaDeferredPrompt) return;
                            window.pwaDeferredPrompt.prompt();
                            const { outcome } = await window.pwaDeferredPrompt.userChoice;
                            if (outcome === 'accepted') {
                                this.showBtn = false;
                                window.pwaDeferredPrompt = null;
                            }
                        }
                    }"
                    x-show="showBtn"
                    class="flex items-center mr-2"
                >
                    <button
                        @click="install()"
                        class="bg-blue-600 hover:bg-blue-700 text-white ml-2 px-3 py-1.5 rounded-lg text-sm font-bold shadow-sm transition-colors flex items-center gap-1"
                        title="Install HMS App"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Install App
                    </button>
                </div>
            HTML)
        );

        // ─── Topbar: Shift management menu item ───────────────────────────────────
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn () => Blade::render(<<<'HTML'
                <li>
                    <a href="#"
                       @click.prevent="$dispatch('open-shift-modal')"
                       class="flex items-center justify-center px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded-md transition-colors cursor-pointer touch-manipulation"
                       title="Shift Management"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </a>
                </li>
            HTML)
        );

        // ─── Body End: Notification sound + badge watcher + Shift Manager ────────
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => Blade::render(<<<'HTML'
                <audio id="notification-sound" src="/sounds/notification.wav" preload="auto"></audio>

                <script>
                    (function () {
                        var userId = @json(auth()->id());
                        var initKey = 'notificationSystem_' + userId;

                        if (window[initKey]) return;
                        window[initKey] = true;

                        var notificationCount = 0;
                        var audioReady = false;

                        document.addEventListener('click', function () {
                            if (!audioReady) {
                                var audio = document.getElementById('notification-sound');
                                audio.play().then(function () {
                                    audio.pause();
                                    audio.currentTime = 0;
                                    audioReady = true;
                                }).catch(function (e) {
                                    console.log('Audio unlock failed:', e);
                                });
                            }
                        }, { once: true });

                        var badgeCheckInterval = setInterval(function () {
                            var badge = document.querySelector('.fi-icon-btn-badge, [class*="badge"]');
                            if (badge) {
                                var currentCount = parseInt(badge.textContent) || 0;
                                if (currentCount > notificationCount && notificationCount > 0 && audioReady) {
                                    var audio = document.getElementById('notification-sound');
                                    audio.currentTime = 0;
                                    audio.play().catch(function (err) {
                                        console.error('Sound play failed:', err);
                                    });
                                }
                                notificationCount = currentCount;
                            }
                        }, 2000);

                        window.addEventListener('beforeunload', function () {
                            clearInterval(badgeCheckInterval);
                        });
                    })();
                </script>

                @livewire('shift-manager')
            HTML)
        );
    }
}
