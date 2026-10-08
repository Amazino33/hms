<?php

require_once __DIR__.'/AttendanceTestHelpers.php';

use App\Models\Attendance\AttendanceFine;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Services\Attendance\ShiftFinaliser;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // The routine floor stays where it is; backfill is a separate path.
    config(['attendance.engine_start_date' => '2026-10-08']);

    $this->settings = AttendanceSetting::create([
        'effective_from' => '2026-09-01',
        'absence_day_pay_amount' => 2500,
    ]);

    $this->template = AttendanceShiftTemplate::create([
        'name' => 'Day shift',
        'start_time' => '08:00:00',
        'duration_minutes' => 600,
        'pattern_type' => 'weekly',
        'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
    ]);
});

function backfill(string $from, string $to, bool $dryRun = false): array
{
    return app(ShiftFinaliser::class)->backfill(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        null,
        $dryRun,
    );
}

it('judges history the routine run can never reach', function () {
    staffed('7', from: '2026-10-01');
    logPunch('7', '2026-10-05 09:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');

    // The scheduled finaliser is floored at 8 October and finds nothing.
    finaliseAt('2026-10-05 23:30');
    expect(AttendanceShiftRecord::count())->toBe(0);

    backfill('2026-10-05', '2026-10-05');

    $record = AttendanceShiftRecord::sole();
    expect($record->outcome)->toBe('late');
    expect($record->shift_date->toDateString())->toBe('2026-10-05');
});

it('never charges for history, whatever the settings say', function () {
    // Rules fully live, which the engine would normally honour.
    config(['attendance.allow_live_fines' => true]);
    AttendanceSetting::query()->update(['shadow_mode' => false, 'rules_start_date' => '2026-09-01']);

    staffed('7', from: '2026-09-01');
    backfill('2026-09-15', '2026-09-15');

    // Staff were not told the rules existed on that day, so a charge they had
    // no chance to avoid is not a fine.
    expect(AttendanceFine::count())->toBeGreaterThan(0);
    expect(AttendanceFine::get()->every(fn ($f) => $f->is_shadow))->toBeTrue();
});

it('writes nothing on a dry run but reports the same totals', function () {
    staffed('7', from: '2026-10-01');

    $dry = backfill('2026-10-05', '2026-10-06', dryRun: true);

    expect(AttendanceShiftRecord::count())->toBe(0);
    expect(AttendanceFine::count())->toBe(0);
    expect($dry['judged'])->toBe(2);

    $real = backfill('2026-10-05', '2026-10-06');

    expect($real['judged'])->toBe($dry['judged']);
    expect($real['fines'])->toBe($dry['fines']);
    expect(AttendanceShiftRecord::count())->toBe(2);
});

it('leaves an existing record alone rather than judging it twice', function () {
    staffed('7', from: '2026-10-01');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');

    backfill('2026-10-05', '2026-10-05');
    $counts = backfill('2026-10-05', '2026-10-05');

    expect(AttendanceShiftRecord::count())->toBe(1);
    expect($counts['judged'])->toBe(0);
    expect($counts['skipped_existing'])->toBe(1);
});

it('does not reach past the day somebody left', function () {
    $user = staffed('7', from: '2026-10-01');
    $user->forceFill(['left_at' => CarbonImmutable::parse('2026-10-03 10:00', \App\Support\VenueTime::TIMEZONE)->utc()])->save();

    backfill('2026-10-01', '2026-10-07');

    expect(AttendanceShiftRecord::whereDate('shift_date', '>=', '2026-10-03')->count())->toBe(0);
    expect(AttendanceShiftRecord::count())->toBe(2);
});

it('stops with guidance when nobody has a schedule', function () {
    $this->artisan('attendance:backfill --from=2026-10-01 --to=2026-10-07')
        ->expectsOutputToContain('Nobody has a shift schedule')
        ->assertFailed();

    expect(AttendanceShiftRecord::count())->toBe(0);
});

it('warns when the range reaches into the hour-fast era', function () {
    staffed('7', from: '2026-09-01');

    $this->artisan('attendance:backfill --from=2026-09-01 --to=2026-09-02 --dry-run')
        ->expectsOutputToContain('stored an hour fast')
        ->assertSuccessful();
});

it('asks before writing and writes nothing when declined', function () {
    staffed('7', from: '2026-10-01');

    $this->artisan('attendance:backfill --from=2026-10-05 --to=2026-10-05')
        ->expectsConfirmation('Write these records?', 'no')
        ->assertSuccessful();

    expect(AttendanceShiftRecord::count())->toBe(0);
});

it('writes when confirmed', function () {
    staffed('7', from: '2026-10-01');

    $this->artisan('attendance:backfill --from=2026-10-05 --to=2026-10-05')
        ->expectsConfirmation('Write these records?', 'yes')
        ->assertSuccessful();

    expect(AttendanceShiftRecord::count())->toBe(1);
});
