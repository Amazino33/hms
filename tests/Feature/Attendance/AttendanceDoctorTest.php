<?php

require_once __DIR__.'/AttendanceTestHelpers.php';

use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftTemplate;

/**
 * An empty board has several possible causes that look identical on screen.
 * The doctor exists so the right one is named rather than guessed at.
 */
it('names the missing rules when none have been saved', function () {
    $this->artisan('attendance:doctor')
        ->expectsOutputToContain('No attendance rules have been saved')
        ->assertSuccessful();
});

it('names the missing schedule, which is the usual cause', function () {
    AttendanceSetting::create(['effective_from' => '2026-10-01']);
    AttendanceShiftTemplate::create([
        'name' => 'Day shift',
        'start_time' => '08:00:00',
        'duration_minutes' => 600,
        'pattern_type' => 'weekly',
        'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
    ]);

    $this->artisan('attendance:doctor')
        ->expectsOutputToContain('Nobody has a shift schedule covering this date')
        ->assertSuccessful();
});

it('explains the engine window for a date before it starts', function () {
    config(['attendance.engine_start_date' => '2026-10-08']);
    AttendanceSetting::create(['effective_from' => '2026-09-01']);

    $this->artisan('attendance:doctor --date=2026-09-15')
        ->expectsOutputToContain('before the engine start date')
        ->expectsOutputToContain('attendance:backfill')
        ->assertSuccessful();
});

it('explains that a shift is simply not finished yet', function () {
    config(['attendance.engine_start_date' => '2026-10-01']);

    $this->settings = AttendanceSetting::create(['effective_from' => '2026-10-01']);
    $this->template = AttendanceShiftTemplate::create([
        'name' => 'Day shift',
        'start_time' => '08:00:00',
        'duration_minutes' => 600,
        'pattern_type' => 'weekly',
        'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
    ]);

    staffed('7', from: '2026-10-01');

    $this->artisan('attendance:doctor --date='.now()->timezone(\App\Support\VenueTime::TIMEZONE)->toDateString())
        ->expectsOutputToContain('none have been judged yet')
        ->assertSuccessful();
});
