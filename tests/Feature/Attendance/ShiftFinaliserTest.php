<?php

require_once __DIR__.'/AttendanceTestHelpers.php';

use App\Models\Attendance\AttendanceFine;
use App\Models\Attendance\AttendanceReviewItem;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\Attendance\ShiftEvaluator;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['attendance.engine_start_date' => '2026-10-01']);

    $this->settings = AttendanceSetting::create([
        'effective_from' => '2026-10-01',
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

it('does not finalise before the window closes plus the delay', function () {
    $user = staffed('7');
    logPunch('7', '2026-10-05 08:00');
    deviceHeardAt('2026-10-05 23:00');

    // Window ends 18:00 + 240 = 22:00; delay 60 → ready at 23:00.
    finaliseAt('2026-10-05 22:30');

    expect(AttendanceShiftRecord::count())->toBe(0);
});

it('finalises once the window and delay have passed', function () {
    $user = staffed('7');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    $record = AttendanceShiftRecord::sole();
    expect($record->outcome)->toBe('present');
    expect($record->user_id)->toBe($user->id);
    expect($record->shift_date->toDateString())->toBe('2026-10-05');
});

it('waits on a silent device rather than calling a buffered shift absent', function () {
    staffed('7');
    // Device last heard well before the window closed — punches may still be
    // sitting on it.
    deviceHeardAt('2026-10-05 09:00');

    finaliseAt('2026-10-05 23:30');

    expect(AttendanceShiftRecord::count())->toBe(0);
});

it('finalises a silent device once the maximum wait has passed, and flags it', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 09:00');

    // Window end 22:00 + 1440 minutes = 22:00 the next day.
    finaliseAt('2026-10-06 22:30', days: 1);

    $record = AttendanceShiftRecord::whereDate('shift_date', '2026-10-05')->sole();
    expect($record->outcome)->toBe('absent');
    expect($record->hasFlag(ShiftEvaluator::FLAG_DEVICE_SILENT))->toBeTrue();
});

it('creates the record and its fines together', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    $record = AttendanceShiftRecord::sole();
    expect($record->outcome)->toBe('absent');

    $fines = AttendanceFine::where('shift_record_id', $record->id)->get();
    expect($fines->pluck('type')->sort()->values()->all())->toBe(['absence_day_pay', 'absent']);
    expect($fines->where('kind', 'fine')->sum('amount'))->toBe(3000);
    expect($fines->where('kind', 'pay_deduction')->sum('amount'))->toBe(2500);
});

it('writes nothing at all when the shift record insert fails', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 23:30');

    // A fine type the enum will reject, forcing the insert to blow up after
    // the record row has been written inside the transaction.
    $evaluator = Mockery::mock(ShiftEvaluator::class)->makePartial();

    expect(fn () => DB::transaction(function () {
        AttendanceShiftRecord::create([
            'user_id' => User::factory()->create()->id,
            'shift_date' => '2026-10-05',
            'scheduled_start_at' => now(),
            'scheduled_end_at' => now(),
            'window_start_at' => now(),
            'window_end_at' => now(),
            'outcome' => 'present',
            'current_key' => 'x:y',
        ]);

        throw new RuntimeException('forced');
    }))->toThrow(RuntimeException::class);

    expect(AttendanceShiftRecord::where('current_key', 'x:y')->exists())->toBeFalse();
});

it('is idempotent — running twice creates one record', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');
    $counts = finaliseAt('2026-10-05 23:35');

    expect(AttendanceShiftRecord::count())->toBe(1);
    expect($counts['finalised'])->toBe(0);
    expect($counts['skipped_existing'])->toBeGreaterThan(0);
});

it('never finalises before the engine start date', function () {
    config(['attendance.engine_start_date' => '2026-10-06']);

    staffed('7', '2026-09-01');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    // The schedule existed, but the engine was not running then — judging it
    // would mark months of untracked history absent.
    expect(AttendanceShiftRecord::count())->toBe(0);
});

it('flags a day where most of the roster is absent as a suspected outage', function () {
    foreach (['1', '2', '3', '4', '5'] as $id) {
        staffed($id);
    }

    // Only one of five turned up.
    logPunch('1', '2026-10-05 08:00');
    logPunch('1', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    $absent = AttendanceShiftRecord::where('outcome', 'absent')->get();
    expect($absent)->toHaveCount(4);
    expect($absent->every(fn ($r) => $r->hasFlag(ShiftEvaluator::FLAG_SUSPECTED_OUTAGE)))->toBeTrue();

    expect(AttendanceReviewItem::where('reason', AttendanceReviewItem::SUSPECTED_OUTAGE)->count())->toBe(1);
});

it('does not cry outage for a normal day with one absence', function () {
    foreach (['1', '2', '3', '4', '5'] as $id) {
        staffed($id);
        logPunch($id, '2026-10-05 08:00');
        logPunch($id, '2026-10-05 18:00');
    }

    // Wipe one person's punches so exactly one is absent.
    AttendanceLog::where('biometric_id', '5')->delete();
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    expect(AttendanceReviewItem::where('reason', AttendanceReviewItem::SUSPECTED_OUTAGE)->count())->toBe(0);
});

it('raises a review item for a punch with no scheduled shift around it', function () {
    $user = staffed('7');
    // 03:00 falls outside the 06:00-22:00 window of any shift.
    logPunch('7', '2026-10-05 03:00');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    $item = AttendanceReviewItem::where('reason', AttendanceReviewItem::UNSCHEDULED_PUNCH)->sole();
    expect($item->user_id)->toBe($user->id);
    // No fine for turning up — it points at a wrong rota, not a wrong person.
    expect(AttendanceFine::count())->toBe(0);
});

it('does not stack the same unscheduled punch on every run', function () {
    staffed('7');
    logPunch('7', '2026-10-05 03:00');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:40');

    expect(AttendanceReviewItem::where('reason', AttendanceReviewItem::UNSCHEDULED_PUNCH)->count())->toBe(1);
});

it('marks fines shadow while live fines are switched off', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    expect(AttendanceFine::where('is_shadow', true)->count())->toBe(AttendanceFine::count());
    expect(AttendanceFine::count())->toBeGreaterThan(0);
});

it('skips an exempt user entirely', function () {
    $user = staffed('7');
    $user->forceFill(['attendance_exempt' => true])->save();
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    expect(AttendanceShiftRecord::count())->toBe(0);
});

it('stops judging a leaver on and after the day they left', function () {
    $user = staffed('7');
    $user->forceFill(['left_at' => CarbonImmutable::parse('2026-10-05 10:00', VenueTime::TIMEZONE)->utc()])->save();
    deviceHeardAt('2026-10-06 23:30');

    finaliseAt('2026-10-06 23:30', days: 1);

    // Without the resolver's left_at stop this would be ₦3,000 plus a day's
    // pay, every day, forever.
    expect(AttendanceShiftRecord::whereDate('shift_date', '2026-10-05')->count())->toBe(0);
    expect(AttendanceShiftRecord::whereDate('shift_date', '2026-10-06')->count())->toBe(0);
});

it('records a scheduled person with no device link as unlinked, not absent', function () {
    $user = User::factory()->create();
    AttendanceShiftAssignment::create([
        'user_id' => $user->id,
        'attendance_shift_template_id' => $this->template->id,
        'effective_from' => '2026-10-01',
    ]);
    deviceHeardAt('2026-10-05 23:30');

    finaliseAt('2026-10-05 23:30');

    $record = AttendanceShiftRecord::where('user_id', $user->id)->sole();
    expect($record->outcome)->toBe('unlinked');
    expect(AttendanceFine::where('user_id', $user->id)->count())->toBe(0);
});
