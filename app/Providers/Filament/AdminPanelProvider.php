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

    public function boot()
    {
        // Register mobile sidebar close button
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

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => Blade::render(<<<'HTML'
            <audio id="notification-sound" src="/sounds/notification.wav" preload="auto"></audio>

            <script>
                (function() {
                    // User-specific notification monitoring to prevent conflicts across sessions
                    // Using data from server-side rendered auth()->user()
                    const userId = document.documentElement.getAttribute('data-user-id') || @json(auth()->id());
                    const initKey = `notificationSystem_${userId}`;
                    
                    // Prevent re-initialization for the same user
                    if (window[initKey]) return;
                    window[initKey] = true;
                    
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
                                console.log('🔊 Notification sound ready for user ' + userId);
                            }).catch(e => console.log('Audio unlock failed:', e));
                        }
                    }, { once: true });

                    // Monitor notification badge for changes (user-specific)
                    const badgeCheckInterval = setInterval(() => {
                        const badge = document.querySelector('.fi-icon-btn-badge, [class*="badge"]');
                        
                        if (badge) {
                            const currentCount = parseInt(badge.textContent) || 0;
                            
                            // Play sound when count increases (skip initial count)
                            if (currentCount > notificationCount && notificationCount > 0 && audioReady) {
                                const audio = document.getElementById('notification-sound');
                                audio.currentTime = 0;
                                audio.play().catch(err => console.error('Sound play failed:', err));
                                console.log(`📢 Notification for user ${userId}: ${currentCount} items`);
                            }
                            
                            notificationCount = currentCount;
                        }
                    }, 2000);
                    
                    // Cleanup on page unload
                    window.addEventListener('beforeunload', () => clearInterval(badgeCheckInterval));
                })();
            </script>

            <!-- Shift Manager Component -->
            @livewire('shift-manager')
        HTML)
        );

        // Add shift management menu item
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn () => Blade::render(<<<'HTML'
            <li>
                <a href="#"
                   @@click.prevent="$dispatch('open-shift-modal')"
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

        // Hide notification bell badge
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn () => Blade::render(<<<'HTML'
            <style>
                .fi-topbar-database-notifications-btn .fi-icon-btn-badge {
                    display: none !important;
                }
            </style>
        HTML)
        );

        // Register Service Worker for PWA functionality
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn () => Blade::render(<<<'HTML'
            <style>
                #fi-nprogress {
                    position: fixed;
                    top: 0; left: 0;
                    width: 0%;
                    height: 3px;
                    background: #f59e0b;
                    z-index: 99999;
                    transition: width 0.15s ease;
                    border-radius: 0 2px 2px 0;
                    box-shadow: 0 0 8px rgba(245,158,11,0.6);
                    pointer-events: none;
                    opacity: 0;
                }
            </style>
            <div id="fi-nprogress"></div>
            <script>
                (function() {
                    const bar = () => document.getElementById('fi-nprogress');

                    // Show bar on any navigation click inside Filament
                    document.addEventListener('click', function(e) {
                        const link = e.target.closest('a[href]');
                        if (!link) return;
                        const href = link.getAttribute('href');
                        if (!href || href.startsWith('#') || href.startsWith('javascript') || link.target === '_blank') return;
                        const b = bar();
                        if (b) { b.style.opacity = '1'; b.style.width = '60%'; }
                    });

                    // Complete bar on page load
                    window.addEventListener('load', function() {
                        const b = bar();
                        if (b && b.style.width !== '0%') {
                            b.style.width = '100%';
                            setTimeout(() => { b.style.opacity = '0'; b.style.width = '0%'; }, 250);
                        }
                    });

                    // Also handle Livewire navigations inside admin
                    document.addEventListener('livewire:navigate', () => {
                        const b = bar();
                        if (b) { b.style.opacity = '1'; b.style.width = '65%'; }
                    });
                    document.addEventListener('livewire:navigated', () => {
                        const b = bar();
                        if (b) {
                            b.style.width = '100%';
                            setTimeout(() => { b.style.opacity = '0'; b.style.width = '0%'; }, 250);
                        }
                    });

                    if ('serviceWorker' in navigator) {
                        window.addEventListener('load', function() {
                            navigator.serviceWorker.register('/sw.js')
                                .then(function(registration) {
                                    console.log('✅ ServiceWorker registered:', registration.scope);
                                    setInterval(function() { registration.update(); }, 60 * 60 * 1000);
                                })
                                .catch(function(err) {
                                    console.error('❌ ServiceWorker registration failed:', err);
                                });
                        });
                    }
                })();
            </script>
        HTML)
        );

        FilamentView::registerRenderHook(
            'panels::head.end',
            fn (): string => Blade::render('
            <link rel="manifest" href="/manifest.json">
            <meta name="theme-color" content="#2563eb">
            <link rel="apple-touch-icon" href="/icons/icon-192.png">
        ')
        );

        FilamentView::registerRenderHook(
            'panels::scripts.after',
            fn (): string => Blade::render("
            <script>
                if ('serviceWorker' in navigator) {
                    window.addEventListener('load', () => {
                        navigator.serviceWorker.register('/sw.js');
                    });
                }
            </script>
        ")
        );
    }
}
