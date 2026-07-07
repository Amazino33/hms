<?php

use App\Http\Middleware\EnsureValidKioskDevice;
use App\Models\KioskDevice;
use App\Models\User;
use App\Services\KioskDeviceService;

it('shows the registration form without requiring any login', function () {
    $this->get('/kiosk/register')->assertStatus(200);
});

it('registers a device end-to-end: valid code sets a cookie that then grants access to kiosk routes', function () {
    $admin = User::factory()->create();
    ['code' => $code] = (new KioskDeviceService())->generateRegistrationCode($admin);

    $response = $this->post('/kiosk/register', [
        'device_name' => 'Bar Kiosk 1',
        'code' => $code,
    ]);

    $response->assertRedirect(route('kiosk.home'));
    $cookie = $response->headers->getCookies();
    $tokenCookie = collect($cookie)->first(fn ($c) => $c->getName() === EnsureValidKioskDevice::COOKIE_NAME);

    expect($tokenCookie)->not->toBeNull();
    expect(KioskDevice::where('name', 'Bar Kiosk 1')->exists())->toBeTrue();

    // Follow through with the cookie set to prove it actually grants access.
    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $tokenCookie->getValue())
        ->get('/kiosk')
        ->assertStatus(200);
});

it('rejects registration with an invalid code and creates no device', function () {
    $response = $this->post('/kiosk/register', [
        'device_name' => 'Bar Kiosk 1',
        'code' => 'GARBAGE1',
    ]);

    $response->assertSessionHasErrors('code');
    expect(KioskDevice::count())->toBe(0);
});

it('blocks /kiosk with no device cookie at all', function () {
    $this->get('/kiosk')->assertStatus(401);
});

it('blocks /kiosk once the device has been revoked', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();
    ['code' => $code] = $service->generateRegistrationCode($admin);
    ['device' => $device, 'token' => $token] = $service->registerDevice($code, 'Bar Kiosk 1');

    $service->revoke($device, $admin);

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token)
        ->get('/kiosk')
        ->assertStatus(401);
});
