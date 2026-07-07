<?php

use App\Exceptions\PinLockedException;
use App\Models\User;
use App\Services\PinAuthService;
use Spatie\Activitylog\Models\Activity;

it('sets a valid pin, hashing it and never storing it in plain form', function () {
    $user = User::factory()->create();

    (new PinAuthService())->setPin($user, '5739');

    $user->refresh();
    expect($user->pin_hash)->not->toBeNull();
    expect($user->pin_hash)->not->toBe('5739');
    expect($user->pin_lookup_hash)->not->toBeNull();
    expect($user->pin_set_at)->not->toBeNull();
});

it('rejects a pin that is not exactly 4 digits', function () {
    $user = User::factory()->create();
    $service = new PinAuthService();

    expect(fn () => $service->setPin($user, '123'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->setPin($user, '12345'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->setPin($user, 'abcd'))->toThrow(InvalidArgumentException::class);
});

it('rejects trivial pins', function (string $pin) {
    $user = User::factory()->create();

    expect(fn () => (new PinAuthService())->setPin($user, $pin))->toThrow(InvalidArgumentException::class);
})->with(['0000', '1111', '9999', '1234', '4321', '0123', '9876']);

it('accepts a non-trivial pin that is not a simple sequence', function () {
    $user = User::factory()->create();

    (new PinAuthService())->setPin($user, '5739');

    expect($user->fresh()->pin_hash)->not->toBeNull();
});

it('enforces pin uniqueness across users', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $service = new PinAuthService();

    $service->setPin($userA, '5739');

    expect(fn () => $service->setPin($userB, '5739'))->toThrow(InvalidArgumentException::class);
});

it('lets a user change their own pin to a new value without tripping the uniqueness check on themselves', function () {
    $user = User::factory()->create();
    $service = new PinAuthService();

    $service->setPin($user, '5739');
    $service->setPin($user, '8462');

    expect($user->fresh()->pin_hash)->not->toBeNull();
});

it('force-resets a pin so the user must set a new one, and logs who did it', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create();
    $service = new PinAuthService();

    $service->setPin($user, '5739');
    $service->forceReset($user, $manager);

    $user->refresh();
    expect($user->pin_hash)->toBeNull();
    expect($user->pin_lookup_hash)->toBeNull();
    expect($user->pin_set_at)->toBeNull();

    $activity = Activity::where('log_name', 'pin')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->causer_id)->toBe($manager->id);
    expect($activity->subject_id)->toBe($user->id);
});

it('resolves the correct user from a correct pin entry', function () {
    $user = User::factory()->create();
    $service = new PinAuthService();
    $service->setPin($user, '5739');

    $resolved = $service->attempt('5739', 'device-1');

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($user->id);
});

it('returns null for a pin that matches no user', function () {
    $service = new PinAuthService();

    $resolved = $service->attempt('5739', 'device-1');

    expect($resolved)->toBeNull();
});

it('locks out a device after 5 consecutive failed attempts and logs it', function () {
    $service = new PinAuthService();

    for ($i = 0; $i < 5; $i++) {
        $service->attempt('9999', 'device-1'); // never a valid pin (trivial pins are never set)
    }

    expect($service->isLocked('device-1'))->toBeTrue();

    $activity = Activity::where('log_name', 'pin')->where('description', 'like', '%lockout%')->latest('id')->first();
    expect($activity)->not->toBeNull();
});

it('throws PinLockedException on any attempt while locked, even with the correct pin', function () {
    $user = User::factory()->create();
    $service = new PinAuthService();
    $service->setPin($user, '5739');

    for ($i = 0; $i < 5; $i++) {
        $service->attempt('9999', 'device-1');
    }

    expect(fn () => $service->attempt('5739', 'device-1'))->toThrow(PinLockedException::class);
});

it('clears the failure counter and any lock after a successful attempt', function () {
    $user = User::factory()->create();
    $service = new PinAuthService();
    $service->setPin($user, '5739');

    $service->attempt('9999', 'device-1');
    $service->attempt('9999', 'device-1');
    $service->attempt('5739', 'device-1'); // succeeds, should reset counter

    expect($service->isLocked('device-1'))->toBeFalse();

    // 4 more failures should NOT lock (counter was reset, so this is only failure #4 since success)
    for ($i = 0; $i < 4; $i++) {
        $service->attempt('9999', 'device-1');
    }
    expect($service->isLocked('device-1'))->toBeFalse();
});

it('does not let a lockout on one device affect a different device', function () {
    $user = User::factory()->create();
    $service = new PinAuthService();
    $service->setPin($user, '5739');

    for ($i = 0; $i < 5; $i++) {
        $service->attempt('9999', 'device-1');
    }

    expect($service->isLocked('device-1'))->toBeTrue();
    expect($service->isLocked('device-2'))->toBeFalse();

    // A correct attempt on the unaffected device still works.
    $resolved = $service->attempt('5739', 'device-2');
    expect($resolved->id)->toBe($user->id);
});
