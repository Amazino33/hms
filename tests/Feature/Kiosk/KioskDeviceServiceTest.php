<?php

use App\Models\KioskDevice;
use App\Models\KioskRegistrationCode;
use App\Models\User;
use App\Services\KioskDeviceService;

it('generates a registration code without ever storing it in plain form', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();

    ['code' => $code, 'record' => $record] = $service->generateRegistrationCode($admin);

    expect(strlen($code))->toBe(8);
    expect($record->code_hash)->not->toBe($code);
    expect($record->expires_at->isFuture())->toBeTrue();
    expect($record->created_by)->toBe($admin->id);
});

it('registers a device from a valid code and returns a raw token that resolves back to it', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();

    ['code' => $code] = $service->generateRegistrationCode($admin);
    ['device' => $device, 'token' => $token] = $service->registerDevice($code, 'Bar Kiosk 1');

    expect($device->name)->toBe('Bar Kiosk 1');
    expect($device->registered_by)->toBe($admin->id);
    expect(strlen($token))->toBeGreaterThan(30);

    $resolved = $service->resolveToken($token);
    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($device->id);
});

it('marks the registration code used so it cannot be redeemed twice', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();

    ['code' => $code] = $service->generateRegistrationCode($admin);
    $service->registerDevice($code, 'Bar Kiosk 1');

    expect(fn () => $service->registerDevice($code, 'Bar Kiosk 2'))->toThrow(Exception::class);
});

it('rejects an expired registration code', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();

    ['code' => $code, 'record' => $record] = $service->generateRegistrationCode($admin);
    $record->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $service->registerDevice($code, 'Bar Kiosk 1'))->toThrow(Exception::class);
});

it('rejects a garbage/incorrect registration code', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();
    $service->generateRegistrationCode($admin);

    expect(fn () => $service->registerDevice('WRONGCODE', 'Bar Kiosk 1'))->toThrow(Exception::class);
});

it('kills a revoked device token immediately on the next resolve attempt', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();

    ['code' => $code] = $service->generateRegistrationCode($admin);
    ['device' => $device, 'token' => $token] = $service->registerDevice($code, 'Bar Kiosk 1');

    expect($service->resolveToken($token))->not->toBeNull();

    $service->revoke($device, $admin);

    expect($service->resolveToken($token))->toBeNull();
});

it('returns null for a token that does not match any device', function () {
    $service = new KioskDeviceService();

    expect($service->resolveToken('totally-made-up-token'))->toBeNull();
});

it('updates last_seen_at every time the token resolves', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();

    ['code' => $code] = $service->generateRegistrationCode($admin);
    ['device' => $device, 'token' => $token] = $service->registerDevice($code, 'Bar Kiosk 1');

    $service->resolveToken($token);
    $before = $device->fresh()->last_seen_at;
    expect($before)->not->toBeNull();

    $this->travel(5)->minutes();
    $service->resolveToken($token);

    expect($device->fresh()->last_seen_at->gt($before))->toBeTrue();
});

it('renames a device', function () {
    $admin = User::factory()->create();
    $service = new KioskDeviceService();

    ['code' => $code] = $service->generateRegistrationCode($admin);
    ['device' => $device] = $service->registerDevice($code, 'Bar Kiosk 1');

    $service->rename($device, 'Bar Kiosk (Main)');

    expect($device->fresh()->name)->toBe('Bar Kiosk (Main)');
});
