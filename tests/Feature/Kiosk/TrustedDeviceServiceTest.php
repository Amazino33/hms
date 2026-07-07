<?php

use App\Models\User;
use App\Services\TrustedDeviceService;

it('trusts a device for a user and resolves the raw token back to that user', function () {
    $user = User::factory()->create();
    $service = new TrustedDeviceService();

    ['token' => $token] = $service->trust($user);

    $resolved = $service->resolveToken($token);
    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($user->id);
});

it('returns null for an unknown token', function () {
    expect((new TrustedDeviceService())->resolveToken('made-up'))->toBeNull();
});

it('kills a revoked trusted device immediately', function () {
    $user = User::factory()->create();
    $service = new TrustedDeviceService();

    ['device' => $device, 'token' => $token] = $service->trust($user);
    expect($service->resolveToken($token))->not->toBeNull();

    $service->revoke($device);

    expect($service->resolveToken($token))->toBeNull();
});

it('lets the same user trust multiple devices independently', function () {
    $user = User::factory()->create();
    $service = new TrustedDeviceService();

    ['token' => $tokenA] = $service->trust($user);
    ['device' => $deviceB, 'token' => $tokenB] = $service->trust($user);

    $service->revoke($deviceB);

    expect($service->resolveToken($tokenA)?->id)->toBe($user->id);
    expect($service->resolveToken($tokenB))->toBeNull();
});
