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
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => Blade::render(<<<'HTML'
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
                    const badge = document.querySelector('.fi-icon-btn-badge, [class*="badge"]');
                    
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
        HTML)
        );
    }
}
