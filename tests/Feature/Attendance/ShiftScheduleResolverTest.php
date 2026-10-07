<?php

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Services\Attendance\ShiftScheduleResolver;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;

/**
 * The resolver decides what somebody was supposed to work, and Phase 2 fines
 * against exactly that. A wrong answer here becomes money taken off a person,
 * so the awkward cases — rotations before their anchor, overnight shifts,
 * mid-range schedule changes — are tested directly rather than assumed.
 */
function resolver(): ShiftScheduleResolver
{
    return app(ShiftScheduleResolver::class);
}

function weeklyTemplate(array $days = [1, 2, 3, 4, 5, 6, 7], string $start = '08:00:00', int $minutes = 600): AttendanceShiftTemplate
{
    return AttendanceShiftTemplate::create([
        'name' => 'Weekly '.implode('', $days),
        'start_time' => $start,
        'duration_minutes' => $minutes,
        'pattern_type' => 'weekly',
        'weekly_days' => $days,
    ]);
}

function rotationTemplate(int $on = 1, int $off = 1, int $minutes = 1440): AttendanceShiftTemplate
{
    return AttendanceShiftTemplate::create([
        'name' => "Rotation {$on}on{$off}off",
        'start_time' => '08:00:00',
        'duration_minutes' => $minutes,
        'pattern_type' => 'rotation',
        'rotation_on_days' => $on,
        'rotation_off_days' => $off,
        'is_handover' => true,
    ]);
}

function assign(User $user, AttendanceShiftTemplate $template, string $from, ?string $anchor = null, ?array $override = null, ?string $to = null): AttendanceShiftAssignment
{
    return AttendanceShiftAssignment::create([
        'user_id' => $user->id,
        'attendance_shift_template_id' => $template->id,
        'effective_from' => $from,
        'effective_to' => $to,
        'rotation_anchor_date' => $anchor,
        'weekly_days_override' => $override,
    ]);
}

function lagos(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, VenueTime::TIMEZONE);
}

it('returns one shift per day for an all-days weekly template', function () {
    $user = User::factory()->create();
    assign($user, weeklyTemplate(), '2026-01-01');

    $shifts = resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-08'));

    expect($shifts)->toHaveCount(7);
});

it('returns only the override days when the assignment narrows the template', function () {
    $user = User::factory()->create();
    // Template says every day; this person works Monday, Wednesday, Friday.
    assign($user, weeklyTemplate(), '2026-01-01', override: [1, 3, 5]);

    $shifts = resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-08'));

    expect($shifts)->toHaveCount(3);
    expect($shifts->pluck('shiftDate')->all())->toBe(['2026-03-02', '2026-03-04', '2026-03-06']);
});

it('ends a 600-minute day shift at 18:00 the same day', function () {
    $user = User::factory()->create();
    assign($user, weeklyTemplate(), '2026-01-01');

    $shift = resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-02'))->sole();

    expect($shift->startsAt->format('Y-m-d H:i'))->toBe('2026-03-02 08:00');
    expect($shift->endsAt->format('Y-m-d H:i'))->toBe('2026-03-02 18:00');
    expect($shift->crossesMidnight())->toBeFalse();
});

it('ends a 24-hour shift at 08:00 the next day and still dates it to the start', function () {
    $user = User::factory()->create();
    assign($user, rotationTemplate(), '2026-01-01', anchor: '2026-03-02');

    $shift = resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-02'))->sole();

    expect($shift->startsAt->format('Y-m-d H:i'))->toBe('2026-03-02 08:00');
    expect($shift->endsAt->format('Y-m-d H:i'))->toBe('2026-03-03 08:00');
    // The shift belongs to the day it began, not the day it ended.
    expect($shift->shiftDate)->toBe('2026-03-02');
    expect($shift->crossesMidnight())->toBeTrue();
});

it('alternates a 1-on-1-off rotation across a week and a month boundary', function () {
    $user = User::factory()->create();
    assign($user, rotationTemplate(), '2026-01-01', anchor: '2026-03-30');

    // Spans the end of March into April.
    $dates = resolver()->expectedShifts($user, lagos('2026-03-30'), lagos('2026-04-05'))
        ->pluck('shiftDate')->all();

    expect($dates)->toBe(['2026-03-30', '2026-04-01', '2026-04-03', '2026-04-05']);
});

it('resolves rotation days before the anchor, not just after it', function () {
    $user = User::factory()->create();
    // Anchored mid-month but backdated to cover the whole month: a negative
    // day difference must still land on the right side of the cycle.
    assign($user, rotationTemplate(), '2026-03-01', anchor: '2026-03-15');

    $dates = resolver()->expectedShifts($user, lagos('2026-03-09'), lagos('2026-03-15'))
        ->pluck('shiftDate')->all();

    // 15th is ON, so odd-numbered days back from it are ON too.
    expect($dates)->toBe(['2026-03-09', '2026-03-11', '2026-03-13', '2026-03-15']);
});

it('covers every day exactly once with two bartenders anchored a day apart', function () {
    $template = rotationTemplate();
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    assign($alice, $template, '2026-01-01', anchor: '2026-01-01');
    assign($bob, $template, '2026-01-01', anchor: '2026-01-02');

    $from = lagos('2026-01-01');
    $to = lagos('2026-03-01'); // 60 days

    $all = resolver()->expectedShifts($alice, $from, $to)
        ->concat(resolver()->expectedShifts($bob, $from, $to))
        ->pluck('shiftDate');

    expect($all)->toHaveCount(60);
    expect($all->unique())->toHaveCount(60);
});

it('switches pattern on the right date when an assignment changes mid-range', function () {
    $user = User::factory()->create();
    $weekdays = weeklyTemplate([1, 2, 3, 4, 5]);
    $weekends = weeklyTemplate([6, 7]);

    assign($user, $weekdays, '2026-03-01', to: '2026-03-04');
    assign($user, $weekends, '2026-03-05');

    $dates = resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-08'))
        ->pluck('shiftDate')->all();

    // Mon-Wed on the weekday pattern, then the weekend pattern takes over
    // from Thursday, whose first matching days are Sat and Sun.
    expect($dates)->toBe(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-07', '2026-03-08']);
});

it('returns nothing for an exempt user even with a live assignment', function () {
    $user = User::factory()->create(['attendance_exempt' => true]);
    assign($user, weeklyTemplate(), '2026-01-01');

    expect(resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-08')))->toBeEmpty();
});

it('returns nothing for a user with no assignment at all', function () {
    $user = User::factory()->create();

    expect(resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-08')))->toBeEmpty();
});

it('ignores dates outside the assignment range', function () {
    $user = User::factory()->create();
    assign($user, weeklyTemplate(), '2026-03-03', to: '2026-03-05');

    $dates = resolver()->expectedShifts($user, lagos('2026-03-01'), lagos('2026-03-08'))
        ->pluck('shiftDate')->all();

    expect($dates)->toBe(['2026-03-03', '2026-03-04', '2026-03-05']);
});

it('returns an empty collection when the range runs backwards', function () {
    $user = User::factory()->create();
    assign($user, weeklyTemplate(), '2026-01-01');

    expect(resolver()->expectedShifts($user, lagos('2026-03-08'), lagos('2026-03-02')))->toBeEmpty();
});

it('previews the next seven shifts for a rotation without running off the end', function () {
    $user = User::factory()->create();
    assign($user, rotationTemplate(), '2026-01-01', anchor: '2026-03-02');

    $shifts = resolver()->nextShifts($user, 7, lagos('2026-03-02'));

    expect($shifts)->toHaveCount(7);
    expect($shifts->first()->shiftDate)->toBe('2026-03-02');
    expect($shifts->last()->shiftDate)->toBe('2026-03-14');
});

it('reports shift times in Lagos even though the app clock is UTC', function () {
    // The guard against a resolver that silently works in config timezone:
    // 08:00 Lagos is 07:00 UTC, so a UTC-built shift would read 08:00 UTC
    // and be an hour out against every real punch.
    expect(config('app.timezone'))->toBe('UTC');

    $user = User::factory()->create();
    assign($user, weeklyTemplate(), '2026-01-01');

    $shift = resolver()->expectedShifts($user, lagos('2026-03-02'), lagos('2026-03-02'))->sole();

    expect($shift->startsAt->timezone->getName())->toBe(VenueTime::TIMEZONE);
    expect($shift->startsAt->utc()->format('H:i'))->toBe('07:00');
});
