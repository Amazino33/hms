<?php

use Illuminate\Support\Facades\Route;

/**
 * Phase 2 guards for the guest QR pages.
 */
function gbSources(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = file_get_contents($file->getPathname());
            }
        }
    }

    ksort($files);

    return $files;
}

it('creates guest requests and their items only in GuestRequestService', function () {
    $creators = [];

    foreach (gbSources([app_path(), resource_path('views'), base_path('routes')]) as $path => $source) {
        if (preg_match('/GuestRequest(Item)?::(create|insert|forceCreate|firstOrCreate|updateOrCreate|make)\s*\(|new\s+GuestRequest(Item)?\s*\(|guest_request(_item)?s[\'"]\)\s*->\s*insert/', $source)) {
            $creators[] = $path;
        }
    }

    expect($creators)->toBe(['app/Services/Guest/GuestRequestService.php']);
});

it('keeps sessions, CSRF and cookie encryption off every guest route (D9)', function () {
    $guestRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'guest.'));

    expect($guestRoutes->map->getName()->sort()->values()->all())->toBe([
        'guest.accounts', 'guest.availability', 'guest.bill', 'guest.calls.store', 'guest.claims.store', 'guest.claims.withdraw',
        'guest.menu', 'guest.place', 'guest.requests', 'guest.requests.cancel', 'guest.requests.submit', 'guest.round',
    ]);

    $forbidden = [
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        'web',
    ];

    foreach ($guestRoutes as $route) {
        $middleware = app('router')->gatherRouteMiddleware($route);

        expect(array_intersect($middleware, $forbidden))->toBe([], "{$route->getName()} runs session/CSRF middleware");
        expect($middleware)->toContain(\App\Http\Middleware\EnsureGuestDevice::class);
        expect(collect($middleware)->contains(fn ($m) => str_starts_with($m, \Illuminate\Routing\Middleware\ThrottleRequests::class)))->toBeTrue("{$route->getName()} is not rate-limited");
    }
});

it('only lets the existing auth middleware call abort() — never a guest controller', function () {
    $callers = [];

    foreach (gbSources([app_path()]) as $path => $source) {
        if (preg_match('/\babort\s*\(/', $source)) {
            $callers[] = $path;
        }
    }

    expect($callers)->toBe([
        'app/Http/Middleware/EnsureStaffPinAuthenticated.php',
        'app/Http/Middleware/EnsureValidKioskDevice.php',
    ]);
});
