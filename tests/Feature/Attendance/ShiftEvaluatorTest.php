<?php

use App\Models\Attendance\AttendanceSetting;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\Attendance\ExpectedShift;
use App\Services\Attendance\PunchWindowAssigner;
use App\Services\Attendance\ShiftEvaluator;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;

/**
 * The evaluator decides what comes out of somebody's wages, so the awkward
 * cases are tested directly rather than inferred from the happy path.
 *
 * It is pure, so none of this needs a database beyond the punch rows
 * themselves — which is the property that makes the simulator trustworthy.
 */
function settings(array $overrides = []): AttendanceSetting
{
    return new AttendanceSetting($overrides + [
        'effective_from' => '2026-10-01',
        'grace_minutes' => 15,
        'duplicate_punch_window_minutes' => 30,
        'fine_late' => 500,
        'fine_late_relief' => 1000,
        'fine_early_leave' => 1500,
        'fine_no_clockout' => 1500,
        'fine_absent' => 3000,
        'window_before_minutes' => 120,
        'window_after_minutes' => 240,
        'early_leave_grace_minutes' => 0,
    ]);
}

/**
 * 08:00–18:00 Lagos on the given date.
 */
function dayShift(string $date = '2026-10-05', bool $handover = false, int $minutes = 600): ExpectedShift
{
    $start = CarbonImmutable::parse($date.' 08:00:00', VenueTime::TIMEZONE);

    return new ExpectedShift(
        userId: 1,
        assignmentId: 1,
        templateId: 1,
        shiftDate: $date,
        startsAt: $start,
        endsAt: $start->addMinutes($minutes),
        isHandover: $handover,
    );
}

/**
 * A punch at a Lagos wall-clock time. Stored UTC, as the real ingestion does.
 */
function punchAt(string $lagos, int $id = 0): AttendanceLog
{
    $log = new AttendanceLog([
        'biometric_id' => '7',
        'punch_time' => CarbonImmutable::parse($lagos, VenueTime::TIMEZONE)->utc(),
    ]);
    $log->id = $id ?: random_int(1, 99999);
    $log->punch_time = CarbonImmutable::parse($lagos, VenueTime::TIMEZONE)->utc();

    return $log;
}

function evaluate(ExpectedShift $shift, array $lagosPunches, array $settingOverrides = [], bool $hasLink = true)
{
    $punches = collect($lagosPunches)->map(fn ($t, $i) => punchAt($t, $i + 1))->values();

    return app(ShiftEvaluator::class)->evaluate($shift, settings($settingOverrides), $punches, $hasLink);
}

// ---------------------------------------------------------------- collapse

it('collapses punches inside the duplicate window into one', function () {
    // Somebody touching the reader three times because it did not beep.
    $result = evaluate(dayShift(), ['2026-10-05 08:00', '2026-10-05 08:10', '2026-10-05 08:25']);

    expect($result->keptPunches)->toHaveCount(1);
    expect($result->rawPunches)->toHaveCount(3);
    expect($result->outcome)->toBe('no_clockout');
});

it('measures the collapse window from the last kept punch, not the previous raw one', function () {
    // 08:00 kept; 08:10 and 08:25 both within 30 min of it, so both dropped;
    // 08:31 is 31 min after the kept 08:00, so it survives.
    $result = evaluate(dayShift(), ['2026-10-05 08:00', '2026-10-05 08:10', '2026-10-05 08:25', '2026-10-05 08:31']);

    expect($result->keptPunches)->toHaveCount(2);
});

it('keeps two punches exactly at the window boundary', function () {
    $result = evaluate(dayShift(), ['2026-10-05 08:00', '2026-10-05 08:31']);

    expect($result->keptPunches)->toHaveCount(2);
});

// ---------------------------------------------------------------- lateness

it('treats exactly the grace period as on time', function () {
    $result = evaluate(dayShift(), ['2026-10-05 08:15', '2026-10-05 18:00']);

    expect($result->lateMinutes)->toBe(15);
    expect($result->outcome)->toBe('present');
    expect($result->fines)->toBe([]);
});

it('fines one minute past the grace period', function () {
    $result = evaluate(dayShift(), ['2026-10-05 08:16', '2026-10-05 18:00']);

    expect($result->lateMinutes)->toBe(16);
    expect($result->outcome)->toBe('late');
    expect($result->totalFines())->toBe(500);
});

it('charges late relief instead of late on a handover shift, never both', function () {
    $result = evaluate(dayShift(handover: true), ['2026-10-05 08:30', '2026-10-05 18:00']);

    expect($result->outcome)->toBe('late_relief');
    expect(collect($result->fines)->pluck('type')->all())->toBe(['late_relief']);
    expect($result->totalFines())->toBe(1000);
});

it('flags an arrival after the midpoint but still charges only lateness', function () {
    $result = evaluate(dayShift(), ['2026-10-05 14:00', '2026-10-05 18:00']);

    expect($result->outcome)->toBe('late');
    expect($result->hasFlag(ShiftEvaluator::FLAG_VERY_LATE))->toBeTrue();
    expect($result->totalFines())->toBe(500);
});

// ------------------------------------------------------------- early leave

it('charges leaving early when the grace is zero', function () {
    $result = evaluate(dayShift(), ['2026-10-05 08:00', '2026-10-05 17:59']);

    expect($result->earlyLeaveMinutes)->toBe(1);
    expect($result->outcome)->toBe('early_leave');
    expect($result->totalFines())->toBe(1500);
});

it('does not charge leaving early inside a five minute grace', function () {
    $result = evaluate(dayShift(), ['2026-10-05 08:00', '2026-10-05 17:59'], ['early_leave_grace_minutes' => 5]);

    expect($result->outcome)->toBe('present');
    expect($result->fines)->toBe([]);
});

it('charges late and early leave together as one combined outcome', function () {
    $result = evaluate(dayShift(), ['2026-10-05 08:30', '2026-10-05 17:00']);

    expect($result->outcome)->toBe('late_and_early_leave');
    expect(collect($result->fines)->pluck('type')->sort()->values()->all())->toBe(['early_leave', 'late']);
    expect($result->totalFines())->toBe(2000);
});

// ------------------------------------------------------------ single punch

it('reads a single early punch as a clock-in with no clock-out', function () {
    $result = evaluate(dayShift(), ['2026-10-05 08:00']);

    expect($result->outcome)->toBe('no_clockout');
    expect($result->clockInAt)->not->toBeNull();
    expect($result->clockOutAt)->toBeNull();
    expect($result->totalFines())->toBe(1500);
});

it('charges both late and no clock-out for a single late punch', function () {
    $result = evaluate(dayShift(), ['2026-10-05 09:00']);

    expect($result->outcome)->toBe('late_no_clockout');
    expect(collect($result->fines)->pluck('type')->sort()->values()->all())->toBe(['late', 'no_clockout']);
    expect($result->totalFines())->toBe(2000);
});

it('reads a single punch after the midpoint as a missed clock-in, not lateness', function () {
    // Charging lateness here would read their departure as their arrival and
    // produce a wildly wrong fine from one missed button press.
    $result = evaluate(dayShift(), ['2026-10-05 17:45']);

    expect($result->outcome)->toBe('no_clockout');
    expect($result->lateMinutes)->toBe(0);
    expect($result->clockInAt)->toBeNull();
    expect($result->clockOutAt)->not->toBeNull();
    expect($result->hasFlag(ShiftEvaluator::FLAG_SINGLE_PUNCH_LATE_HALF))->toBeTrue();
    expect(collect($result->fines)->pluck('type')->all())->toBe(['no_clockout']);
});

it('never charges no clock-out and early leave on the same shift', function () {
    foreach ([['2026-10-05 08:00'], ['2026-10-05 17:45'], ['2026-10-05 09:00']] as $punches) {
        $types = collect(evaluate(dayShift(), $punches)->fines)->pluck('type');

        expect($types->contains('no_clockout') && $types->contains('early_leave'))->toBeFalse();
    }
});

// ----------------------------------------------------------------- absence

it('charges absence plus the day pay deduction when nobody punched', function () {
    $result = evaluate(dayShift(), [], ['absence_day_pay_amount' => 2500]);

    expect($result->outcome)->toBe('absent');
    expect($result->totalFines())->toBe(3000);
    expect($result->totalPayDeductions())->toBe(2500);
});

it('skips the pay deduction and flags it when no day pay figure is set', function () {
    $result = evaluate(dayShift(), [], ['absence_day_pay_amount' => null]);

    expect($result->outcome)->toBe('absent');
    expect($result->totalFines())->toBe(3000);
    expect($result->totalPayDeductions())->toBe(0);
    expect($result->hasFlag(ShiftEvaluator::FLAG_DAY_PAY_NOT_CONFIGURED))->toBeTrue();
});

it('records no punches and no link as unlinked rather than absent', function () {
    // The office never paired them. Charging ₦3,000 for that would be fining
    // somebody for an administrative gap.
    $result = evaluate(dayShift(), [], [], hasLink: false);

    expect($result->outcome)->toBe('unlinked');
    expect($result->fines)->toBe([]);
    expect($result->hasFlag(ShiftEvaluator::FLAG_UNLINKED))->toBeTrue();
});

// ------------------------------------------------------------ overnight

it('treats a 24h handover shift crossing midnight as a single record dated to its start', function () {
    $shift = dayShift('2026-10-05', handover: true, minutes: 1440);

    $result = evaluate($shift, ['2026-10-05 08:05', '2026-10-06 07:58']);

    // One record, not two days split at midnight — which is the whole reason
    // the shift window replaces the calendar-day view.
    expect($result->clockInAt->setTimezone(VenueTime::TIMEZONE)->format('Y-m-d H:i'))->toBe('2026-10-05 08:05');
    expect($result->clockOutAt->setTimezone(VenueTime::TIMEZONE)->format('Y-m-d H:i'))->toBe('2026-10-06 07:58');
    expect($result->shift->shiftDate)->toBe('2026-10-05');
    expect($result->keptPunches)->toHaveCount(2);
});

/**
 * Handing over two minutes early costs ₦1,500 at the default grace of zero.
 *
 * This is the locked rule working exactly as written, and it is almost
 * certainly not what anybody intends for a relief shift — the whole point of a
 * handover is that the next person is already there, so clocking out a minute
 * or two either side of the hour is normal. Recorded as a test rather than
 * quietly softened, because the default is a business decision.
 */
it('charges a two-minute early handover at the default zero grace', function () {
    $shift = dayShift('2026-10-05', handover: true, minutes: 1440);

    $result = evaluate($shift, ['2026-10-05 08:05', '2026-10-06 07:58']);

    expect($result->outcome)->toBe('early_leave');
    expect($result->earlyLeaveMinutes)->toBe(2);
    expect($result->totalFines())->toBe(1500);
});

it('reads the same handover as present once a small early-leave grace is set', function () {
    $shift = dayShift('2026-10-05', handover: true, minutes: 1440);

    $result = evaluate($shift, ['2026-10-05 08:05', '2026-10-06 07:58'], ['early_leave_grace_minutes' => 5]);

    expect($result->outcome)->toBe('present');
    expect($result->fines)->toBe([]);
});

// ------------------------------------------------------- window assignment

it('gives an overlapping punch to exactly one shift, the nearer boundary', function () {
    $tuesday = dayShift('2026-10-05', handover: true, minutes: 1440);
    $wednesday = dayShift('2026-10-06', handover: true, minutes: 1440);

    // 07:55 Wednesday: 5 minutes before Wednesday's start, and 65 minutes
    // before Tuesday's end — so it is Wednesday's clock-in.
    $punches = collect([punchAt('2026-10-06 07:55', 1)]);

    $result = app(PunchWindowAssigner::class)->assign(collect([$tuesday, $wednesday]), $punches, settings());

    expect($result['assigned'][0])->toHaveCount(0);
    expect($result['assigned'][1])->toHaveCount(1);
    expect($result['unassigned'])->toHaveCount(0);
});

it('gives a punch equidistant between two shifts to the later one', function () {
    $first = dayShift('2026-10-05');                    // ends 18:00
    $second = dayShift('2026-10-06');                   // starts 08:00 next day

    // Exactly 7 hours after the first ends and 7 before the second starts.
    $punches = collect([punchAt('2026-10-06 01:00', 1)]);

    $result = app(PunchWindowAssigner::class)->assign(collect([$first, $second]), $punches, settings([
        'window_after_minutes' => 600,
        'window_before_minutes' => 600,
    ]));

    expect($result['assigned'][1])->toHaveCount(1);
    expect($result['assigned'][0])->toHaveCount(0);
});

it('reports a punch belonging to no shift window as unassigned', function () {
    $shift = dayShift('2026-10-05');

    // 03:00 is well outside 06:00–22:00.
    $punches = collect([punchAt('2026-10-05 03:00', 1)]);

    $result = app(PunchWindowAssigner::class)->assign(collect([$shift]), $punches, settings());

    expect($result['unassigned'])->toHaveCount(1);
    expect($result['assigned'][0])->toHaveCount(0);
});

it('never lets one punch count toward two shifts', function () {
    $tuesday = dayShift('2026-10-05', handover: true, minutes: 1440);
    $wednesday = dayShift('2026-10-06', handover: true, minutes: 1440);

    $punches = collect([
        punchAt('2026-10-05 08:02', 1),
        punchAt('2026-10-06 07:58', 2),
        punchAt('2026-10-06 08:05', 3),
    ]);

    $result = app(PunchWindowAssigner::class)->assign(collect([$tuesday, $wednesday]), $punches, settings());

    $total = $result['assigned'][0]->count() + $result['assigned'][1]->count() + $result['unassigned']->count();

    expect($total)->toBe(3);
});

// --------------------------------------------------------------- purity

it('writes nothing to the database', function () {
    $user = User::factory()->create();
    $before = AttendanceLog::count();

    evaluate(dayShift(), ['2026-10-05 08:30', '2026-10-05 17:00'], ['absence_day_pay_amount' => 2500]);

    expect(AttendanceLog::count())->toBe($before);
    expect(\App\Models\Attendance\AttendanceShiftRecord::count())->toBe(0);
    expect(\App\Models\Attendance\AttendanceFine::count())->toBe(0);
});
