<?php

use App\Models\AttendanceLog;
use App\Models\User;

/**
 * The terminal buffers punches while offline and dumps the backlog on its
 * first successful sync, so the opening batch always predates any badge
 * pairing — every one of those rows lands with user_id = NULL and shows a
 * blank Staff Member in the admin table.
 */
it('links orphaned punches to the staff member holding that machine ID', function () {
    $user = User::factory()->create(['biometric_id' => '5', 'name' => 'Ndifreke']);

    $orphan = AttendanceLog::create([
        'user_id' => null,
        'biometric_id' => '5',
        'punch_time' => now()->subDay(),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);

    $this->artisan('hms:link-attendance-logs')->assertSuccessful();

    expect($orphan->fresh()->user_id)->toBe($user->id);
});

it('leaves punches from an unpaired machine ID alone', function () {
    AttendanceLog::create([
        'user_id' => null,
        'biometric_id' => '25',
        'punch_time' => now()->subDay(),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);

    $this->artisan('hms:link-attendance-logs')
        ->expectsOutputToContain('left unlinked')
        ->assertSuccessful();

    expect(AttendanceLog::sole()->user_id)->toBeNull();
});

/**
 * A badge handed to a new starter must not rewrite who was at work last
 * month, so only never-matched rows are ever touched.
 */
it('never re-points a punch that already names someone', function () {
    $original = User::factory()->create(['biometric_id' => null]);
    $current = User::factory()->create(['biometric_id' => '5']);

    $log = AttendanceLog::create([
        'user_id' => $original->id,
        'biometric_id' => '5',
        'punch_time' => now()->subMonth(),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);

    $this->artisan('hms:link-attendance-logs')->assertSuccessful();

    expect($log->fresh()->user_id)->toBe($original->id);
});

it('writes nothing on a dry run', function () {
    User::factory()->create(['biometric_id' => '5']);

    $orphan = AttendanceLog::create([
        'user_id' => null,
        'biometric_id' => '5',
        'punch_time' => now()->subDay(),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);

    $this->artisan('hms:link-attendance-logs', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($orphan->fresh()->user_id)->toBeNull();
});

/**
 * Linking is a records fix, not a disciplinary one — back-dating fines onto
 * staff is management's call, not a side effect of maintenance.
 */
it('does not create late-arrival penalties for the rows it links', function () {
    User::factory()->create(['biometric_id' => '5', 'shift_start_time' => '08:00:00']);

    AttendanceLog::create([
        'user_id' => null,
        'biometric_id' => '5',
        'punch_time' => now()->subDay()->setTime(11, 30),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);

    $this->artisan('hms:link-attendance-logs')->assertSuccessful();

    expect(\App\Models\SalaryDeduction::count())->toBe(0);
});

it('reports cleanly when there is nothing to link', function () {
    $this->artisan('hms:link-attendance-logs')
        ->expectsOutputToContain('No unlinked attendance logs')
        ->assertSuccessful();
});
