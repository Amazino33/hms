<?php

use App\Models\AttendanceLog;
use App\Models\User;

function orphanPunch(string $biometricId, ?string $at = null): AttendanceLog
{
    return AttendanceLog::create([
        'user_id' => null,
        'biometric_id' => $biometricId,
        'punch_time' => $at ? \Carbon\Carbon::parse($at) : now()->subDay(),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);
}

/**
 * The terminal pushes a person's punches long before anyone gets round to
 * typing their machine ID into the staff profile, so pairing has to reach
 * backwards or every newly-paired staff member starts with a wall of
 * blank-named rows.
 */
it('links past punches the moment a biometric ID is saved on a profile', function () {
    $punch = orphanPunch('5');
    $user = User::factory()->create(['biometric_id' => null]);

    $user->update(['biometric_id' => '5']);

    expect($punch->fresh()->user_id)->toBe($user->id);
});

it('links past punches for a user created with the ID already filled in', function () {
    $punch = orphanPunch('5');

    $user = User::factory()->create(['biometric_id' => '5']);

    expect($punch->fresh()->user_id)->toBe($user->id);
});

it('leaves punches from other machine IDs alone', function () {
    $mine = orphanPunch('5');
    $theirs = orphanPunch('25');

    $user = User::factory()->create(['biometric_id' => '5']);

    expect($mine->fresh()->user_id)->toBe($user->id);
    expect($theirs->fresh()->user_id)->toBeNull();
});

/**
 * A badge handed to a new starter must not rewrite who was at work last
 * month — only never-matched rows are ever claimed.
 */
it('does not steal punches already attributed to someone else', function () {
    $original = User::factory()->create(['biometric_id' => '5']);
    $claimed = AttendanceLog::create([
        'user_id' => $original->id,
        'biometric_id' => '5',
        'punch_time' => now()->subMonth(),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);

    $original->update(['biometric_id' => null]);
    $newStarter = User::factory()->create(['biometric_id' => '5']);

    expect($claimed->fresh()->user_id)->toBe($original->id);
    expect($newStarter->id)->not->toBe($original->id);
});

it('does nothing when the biometric ID is cleared', function () {
    $user = User::factory()->create(['biometric_id' => '5']);
    $punch = orphanPunch('5');

    $user->update(['biometric_id' => null]);

    expect($punch->fresh()->user_id)->toBeNull();
});

/**
 * An unrelated profile edit must not re-run the sweep — it would be harmless
 * today but would quietly reclaim rows if the rules ever loosened.
 */
it('does not re-link on an edit that leaves the biometric ID untouched', function () {
    $user = User::factory()->create(['biometric_id' => '5']);
    $late = orphanPunch('5');

    $user->update(['name' => 'Renamed Person']);

    expect($late->fresh()->user_id)->toBeNull();
});
