<?php

use App\Models\Attendance\AttendanceSetting;
use App\Models\SalaryDeduction;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;

/**
 * The handover between the old ₦500 lateness fee and the new engine.
 *
 * The moment the new rules go live for a date is the moment the old path stops
 * for that date, so the two can never both be charging. Checked per date
 * rather than globally, because a backlog pushed after cutover can carry
 * punches from before it, and those belong to the rules in force when they
 * happened.
 */
function pushLatePunch(string $lagos = '2026-10-05 09:00'): void
{
    test()->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "7\t".CarbonImmutable::parse($lagos, VenueTime::TIMEZONE)->format('Y-m-d H:i:s')."\t0\t1\t0\t0\n"
    )->assertOk();
}

beforeEach(function () {
    $this->user = User::factory()->create([
        'biometric_id' => '7',
        'shift_start_time' => '08:00:00',
    ]);
});

it('still charges the legacy lateness fee while the new rules are shadow', function () {
    AttendanceSetting::create(['effective_from' => '2026-10-01', 'shadow_mode' => true]);

    pushLatePunch();

    // Current behaviour preserved exactly — nobody stops being tracked while
    // the new engine is still being proved.
    expect(SalaryDeduction::count())->toBe(1);
    expect((int) SalaryDeduction::sole()->amount)->toBe(500);
});

it('charges nothing legacy once the new rules are live for that date', function () {
    config(['attendance.allow_live_fines' => true]);

    AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'shadow_mode' => false,
        'rules_start_date' => '2026-10-01',
    ]);

    pushLatePunch();

    expect(SalaryDeduction::count())->toBe(0);
});

it('still charges legacy for a backlog punch dated before the rules started', function () {
    config(['attendance.allow_live_fines' => true]);

    AttendanceSetting::create([
        'effective_from' => '2026-09-01',
        'shadow_mode' => false,
        'rules_start_date' => '2026-10-10',
    ]);

    // Pushed today, but it happened before the announced start.
    pushLatePunch('2026-10-05 09:00');

    expect(SalaryDeduction::count())->toBe(1);
});

it('keeps charging legacy when the master switch is off, however the settings read', function () {
    config(['attendance.allow_live_fines' => false]);

    AttendanceSetting::create([
        'effective_from' => '2026-10-01',
        'shadow_mode' => false,
        'rules_start_date' => '2026-10-01',
    ]);

    pushLatePunch();

    // isLiveOn() fails closed, so an incomplete new engine never leaves a gap
    // where nobody is tracked at all.
    expect(SalaryDeduction::count())->toBe(1);
});

it('keeps charging legacy when no attendance rules exist at all', function () {
    config(['attendance.allow_live_fines' => true]);

    pushLatePunch();

    expect(SalaryDeduction::count())->toBe(1);
});

it('does not create shift records or fines from ingestion', function () {
    AttendanceSetting::create(['effective_from' => '2026-10-01']);

    pushLatePunch();

    // Evaluation happens only in the finaliser; the controller just records
    // what the device said.
    expect(\App\Models\Attendance\AttendanceShiftRecord::count())->toBe(0);
    expect(\App\Models\Attendance\AttendanceFine::count())->toBe(0);
});
