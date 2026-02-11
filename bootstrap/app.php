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
        
        // Only trust proxies in actual production environments with real proxies
        // For local Herd development, don't trust proxies
        if (env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: env('TRUSTED_PROXIES'));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();