<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/**
 * Staff 0A §1. Fortify's public sign-up created a role-less user from
 * anywhere on the internet. It is switched off, and nothing unauthenticated
 * creates a user any more.
 */
it('returns 404 for the sign-up page and the sign-up post', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::where('email', 'intruder@example.com')->exists())->toBeFalse();
    expect($this->isAuthenticated())->toBeFalse();
});

it('has no registration feature, route or link left', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
    expect(Route::has('register'))->toBeFalse();
    expect(Route::has('register.store'))->toBeFalse();

    $this->get('/login')->assertOk()->assertDontSee('Sign up');
});

it('has no unauthenticated route whose action creates a user', function () {
    // Every route reachable without 'auth', 'staff_pin.auth' or a Filament
    // panel's own Authenticate, checked against the controllers known to
    // create users. Fortify's RegisteredUserController was the only one.
    $creators = ['RegisteredUserController'];

    $open = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('POST', $route->methods(), true))
        ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (
            str_starts_with($m, 'auth') || $m === 'staff_pin.auth' || str_contains($m, 'Filament\\Http\\Middleware\\Authenticate')
        )))
        ->filter(fn ($route) => collect($creators)->contains(fn ($c) => str_contains((string) $route->getActionName(), $c)));

    expect($open->map(fn ($r) => $r->uri())->values()->all())->toBe([]);
});
