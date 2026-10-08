<?php

require_once __DIR__.'/AttendanceTestHelpers.php';

use App\Models\Attendance\AttendanceFine;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Services\Attendance\ShiftReevaluationService;
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

function reevaluate(string $from, string $to, string $reason = 'test', ?User $user = null): array
{
    return app(ShiftReevaluationService::class)->reevaluate(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        $reason,
        $user,
    );
}

it('changes nothing when the verdict is the same', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $before = AttendanceShiftRecord::sole();

    $counts = reevaluate('2026-10-05', '2026-10-05');

    expect($counts['unchanged'])->toBe(1);
    expect($counts['changed'])->toBe(0);
    expect(AttendanceShiftRecord::count())->toBe(1);
    expect(AttendanceShiftRecord::sole()->id)->toBe($before->id);
});

it('supersedes the old record and voids its fines when the verdict changes', function () {
    staffed('7');
    // Only a clock-in, so the first pass is no_clockout at ₦1,500.
    logPunch('7', '2026-10-05 08:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $old = AttendanceShiftRecord::sole();
    expect($old->outcome)->toBe('no_clockout');
    $oldFine = AttendanceFine::sole();

    // The clock-out finally arrives from the device's backlog.
    logPunch('7', '2026-10-05 18:00');

    $counts = reevaluate('2026-10-05', '2026-10-05', 'late-arriving punch');

    expect($counts['changed'])->toBe(1);

    $old->refresh();
    expect($old->superseded_at)->not->toBeNull();
    expect($old->supersede_reason)->toBe('late-arriving punch');
    expect($old->current_key)->toBeNull();

    $oldFine->refresh();
    expect($oldFine->isVoided())->toBeTrue();
    expect($oldFine->void_reason)->toBe('reevaluated: late-arriving punch');

    // Exactly one current record, and it says present.
    $current = AttendanceShiftRecord::current()->sole();
    expect($current->outcome)->toBe('present');
    expect($current->id)->not->toBe($old->id);
    expect($old->fresh()->superseded_by_id)->toBe($current->id);

    // And nothing live is left to charge.
    expect(AttendanceFine::active()->count())->toBe(0);
});

it('leaves exactly one current record after several re-evaluations', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    logPunch('7', '2026-10-05 18:00');
    reevaluate('2026-10-05', '2026-10-05', 'first');

    logPunch('7', '2026-10-05 17:00');
    reevaluate('2026-10-05', '2026-10-05', 'second');

    expect(AttendanceShiftRecord::current()->count())->toBe(1);
    expect(AttendanceShiftRecord::count())->toBeGreaterThan(1);
});

it('refuses to touch a record whose fine is already in a payroll run', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $record = AttendanceShiftRecord::sole();
    AttendanceFine::where('shift_record_id', $record->id)->update(['payroll_run_id' => 99]);

    logPunch('7', '2026-10-05 18:00');

    $counts = reevaluate('2026-10-05', '2026-10-05', 'backlog');

    // Voiding it here would silently disagree with a payslip somebody already
    // holds, so it is reported instead.
    expect($counts['changed'])->toBe(0);
    expect($counts['skipped_paid'])->toBe([$record->id]);
    expect($record->fresh()->superseded_at)->toBeNull();
    expect(AttendanceFine::sole()->isVoided())->toBeFalse();
});

it('refuses to void a paid fine directly', function () {
    $fine = new AttendanceFine;
    $fine->payroll_run_id = 7;

    expect(fn () => $fine->void('because'))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('keeps the shadow flag of the new fines independent of the old ones', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    expect(AttendanceFine::first()->is_shadow)->toBeTrue();

    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');
    reevaluate('2026-10-05', '2026-10-05', 'punches arrived');

    // The absence fines are voided, not converted — their is_shadow stays as
    // it was written.
    expect(AttendanceFine::whereNotNull('voided_at')->get()->every(fn ($f) => $f->is_shadow))->toBeTrue();
});

it('names the right date range for a punch, covering the night either side', function () {
    $punch = logPunch('7', '2026-10-06 00:30');

    [$from, $to] = app(ShiftReevaluationService::class)->rangeForPunch($punch);

    // A 00:30 punch may belong to the previous evening's overnight shift.
    expect($from->toDateString())->toBe('2026-10-05');
    expect($to->toDateString())->toBe('2026-10-07');
});

it('reports paid skips through the command', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    AttendanceFine::query()->update(['payroll_run_id' => 42]);
    logPunch('7', '2026-10-05 18:00');

    $this->artisan('attendance:reevaluate --from=2026-10-05 --to=2026-10-05 --reason=backlog')
        ->expectsOutputToContain('already in a payroll run')
        ->assertSuccessful();
});

it('requires a reason', function () {
    $this->artisan('attendance:reevaluate --from=2026-10-05 --to=2026-10-05')
        ->expectsOutputToContain('--reason is required')
        ->assertFailed();
});
