<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Add performance headers middleware
        $middleware->append(\App\Http\Middleware\AddPerformanceHeaders::class);

        $middleware->alias([
            'staff_pin.auth' => \App\Http\Middleware\EnsureStaffPinAuthenticated::class,
            'kiosk.device' => \App\Http\Middleware\EnsureValidKioskDevice::class,
            'trusted.device' => \App\Http\Middleware\EnsureTrustedDevice::class,
        ]);

        // Both device tokens are high-entropy bearer values independently
        // verified server-side via a SHA-256 lookup hash — they don't rely on
        // Laravel's cookie encryption for their security, so they're excepted
        // here rather than silently nulled out if ever read/set outside the
        // normal encrypt/decrypt round trip.
        $middleware->encryptCookies(except: [
            \App\Http\Middleware\EnsureValidKioskDevice::COOKIE_NAME,
            \App\Http\Middleware\EnsureTrustedDevice::COOKIE_NAME,
        ]);

        // Only trust proxies in actual production environments with real proxies
        // For local Herd development, don't trust proxies
        if (env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: env('TRUSTED_PROXIES'));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();