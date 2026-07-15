<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * A separate, read-only reporting surface for the CEO — deliberately its
 * own panel (not a role bolted onto the admin panel) so that discovery
 * (discoverResources/discoverPages) can never accidentally pull in an
 * operational admin page. No FilamentShieldPlugin here: this panel has
 * exactly one non-super-admin role (ceo), gated entirely by
 * User::canAccessPanel(), so there is no Shield UI, role/permission
 * editing, or per-resource policy surface to expose. Standard Filament
 * email+password auth via ->login() — no PIN anywhere in this panel.
 */
class CeoPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ceo')
            ->path('ceo')
            ->login()
            ->globalSearch(false)
            ->databaseNotifications(false)
            ->colors([
                'primary' => '#1D4ED8',
            ])
            ->discoverResources(in: app_path('Filament/Ceo/Resources'), for: 'App\\Filament\\Ceo\\Resources')
            ->discoverPages(in: app_path('Filament/Ceo/Pages'), for: 'App\\Filament\\Ceo\\Pages')
            ->discoverWidgets(in: app_path('Filament/Ceo/Widgets'), for: 'App\\Filament\\Ceo\\Widgets')
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
