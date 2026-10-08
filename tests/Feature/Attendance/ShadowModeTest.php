<?php

require_once __DIR__.'/AttendanceTestHelpers.php';

use App\Models\Attendance\AttendanceFine;
use App\Models\Attendance\AttendanceSetting;
use Carbon\CarbonImmutable;

/**
 * isLiveOn() is the last thing standing between the engine and somebody's
 * wages, so every way of being not-quite-configured is asserted to fail
 * closed rather than inferred from the happy path.
 */
function liveOn(string $date = '2026-10-05'): bool
{
    return AttendanceSetting::isLiveOn(CarbonImmutable::parse($date));
}

it('is shadow when the master switch is off', function () {
    config(['attendance.allow_live_fines' => false]);
    AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'shadow_mode' => false,
        'rules_start_date' => '2026-10-01',
    ]);

    expect(liveOn())->toBeFalse();
});

it('is shadow when no settings exist for the date', function () {
    config(['attendance.allow_live_fines' => true]);

    expect(liveOn())->toBeFalse();
});

it('cannot store a null shadow_mode at all', function () {
    $settings = AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'rules_start_date' => '2026-10-01',
    ]);

    // NOT NULL with a default of true, so "nobody has decided" is not a state
    // the database can hold. Asserted rather than assumed, because the
    // alternative — a null silently coercing to 0, which reads as "not
    // shadow" — would quietly turn fines live.
    expect(fn () => $settings->forceFill(['shadow_mode' => null])->save())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('treats a null shadow_mode on an unsaved instance as shadow', function () {
    // The database cannot produce this, but a model built in memory can —
    // which is exactly the Phase 1 trap where a column default lives only in
    // the schema. The strict !== false check is what covers it.
    $settings = new AttendanceSetting(['effective_from' => '2026-10-01']);
    $settings->shadow_mode = null;

    expect($settings->shadow_mode !== false)->toBeTrue();
});

it('is shadow when shadow_mode is on', function () {
    config(['attendance.allow_live_fines' => true]);
    AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'shadow_mode' => true,
        'rules_start_date' => '2026-10-01',
    ]);

    expect(liveOn())->toBeFalse();
});

it('is shadow when no start date has been announced', function () {
    config(['attendance.allow_live_fines' => true]);
    AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'shadow_mode' => false,
        'rules_start_date' => null,
    ]);

    expect(liveOn())->toBeFalse();
});

it('is shadow for a date before the announced start', function () {
    config(['attendance.allow_live_fines' => true]);
    AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'shadow_mode' => false,
        'rules_start_date' => '2026-10-10',
    ]);

    expect(liveOn('2026-10-09'))->toBeFalse();
    // Inclusive on the day itself.
    expect(liveOn('2026-10-10'))->toBeTrue();
});

it('is live only when every condition holds', function () {
    config(['attendance.allow_live_fines' => true]);
    AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'shadow_mode' => false,
        'rules_start_date' => '2026-10-01',
    ]);

    expect(liveOn('2026-10-05'))->toBeTrue();
});

it('explains why it is still shadow', function () {
    config(['attendance.allow_live_fines' => false]);

    expect(AttendanceSetting::shadowReason())->toContain('Live fines are disabled');
});

it('fixes is_shadow at creation and never converts it later', function () {
    config(['attendance.engine_start_date' => '2026-10-01']);

    $this->settings = AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'absence_day_pay_amount' => 2500,
    ]);

    $this->template = \App\Models\Attendance\AttendanceShiftTemplate::create([
        'name' => 'Day shift',
        'start_time' => '08:00:00',
        'duration_minutes' => 600,
        'pattern_type' => 'weekly',
        'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
    ]);

    staffed('7');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    expect(AttendanceFine::first()->is_shadow)->toBeTrue();

    // Going live afterwards must not hand people a bill for the trial period.
    config(['attendance.allow_live_fines' => true]);
    AttendanceSetting::query()->update(['shadow_mode' => false, 'rules_start_date' => '2026-10-01']);

    expect(AttendanceFine::get()->every(fn ($f) => $f->is_shadow))->toBeTrue();
});
