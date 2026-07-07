<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('cannot open a Filament admin panel route while authenticated only via the staff_pin guard', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'staff_pin');

    // No 'web' session exists at all — only staff_pin. Filament's panel is
    // configured to authenticate against 'web' exclusively, so this must
    // never be treated as logged in there.
    $response = $this->get('/admin');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('/admin/dashboard');
});

it('cannot open a Filament resource route while authenticated only via the staff_pin guard', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'staff_pin');

    $response = $this->get('/admin/products');

    $response->assertStatus(302);
});

it('a route protected by the staff_pin.auth middleware rejects a request with no staff_pin session', function () {
    Route::middleware(['web', 'staff_pin.auth'])->get('/__test/kiosk-protected', fn () => 'ok');

    $response = $this->get('/__test/kiosk-protected');

    $response->assertStatus(401);
});

it('a route protected by the staff_pin.auth middleware rejects a plain web-guard session too', function () {
    Route::middleware(['web', 'staff_pin.auth'])->get('/__test/kiosk-protected-2', fn () => 'ok');

    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $response = $this->get('/__test/kiosk-protected-2');

    $response->assertStatus(401);
});

it('a route protected by the staff_pin.auth middleware allows a request with a valid staff_pin session', function () {
    Route::middleware(['web', 'staff_pin.auth'])->get('/__test/kiosk-protected-3', fn () => 'ok');

    $user = User::factory()->create();
    $this->actingAs($user, 'staff_pin');

    $response = $this->get('/__test/kiosk-protected-3');

    $response->assertStatus(200);
    $response->assertSee('ok');
});
