<?php

require_once __DIR__.'/AttendanceTestHelpers.php';

use App\Models\Attendance\AttendanceFine;
use App\Models\Attendance\AttendanceReviewItem;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\AttendanceLog;
use App\Services\Attendance\AttendanceSimulator;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    config(['attendance.engine_start_date' => '2026-10-01']);

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

it('writes nothing to any table', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');

    $before = [
        'records' => AttendanceShiftRecord::count(),
        'fines' => AttendanceFine::count(),
        'reviews' => AttendanceReviewItem::count(),
        'logs' => AttendanceLog::count(),
    ];

    $result = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-10-05'),
        CarbonImmutable::parse('2026-10-05'),
    );

    expect($result['rows'])->toHaveCount(1);

    expect(AttendanceShiftRecord::count())->toBe($before['records']);
    expect(AttendanceFine::count())->toBe($before['fines']);
    expect(AttendanceReviewItem::count())->toBe($before['reviews']);
    expect(AttendanceLog::count())->toBe($before['logs']);
});

it('produces the same verdict the engine would', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:30');
    logPunch('7', '2026-10-05 18:00');

    $result = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-10-05'),
        CarbonImmutable::parse('2026-10-05'),
    );

    $row = $result['rows']->sole();
    expect($row['outcome'])->toBe('late');
    expect($row['fines'])->toBe(500);
    expect($row['late_minutes'])->toBe(30);
});

it('projects the current pattern backwards when asked', function () {
    // The assignment only starts in October, but September is the month worth
    // back-testing.
    staffed('7', from: '2026-10-01');
    logPunch('7', '2026-09-15 08:00');
    logPunch('7', '2026-09-15 18:00');

    $without = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-09-15'),
        CarbonImmutable::parse('2026-09-15'),
    );
    expect($without['rows'])->toHaveCount(0);

    $with = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-09-15'),
        CarbonImmutable::parse('2026-09-15'),
        assumeCurrentSchedules: true,
    );
    expect($with['rows'])->toHaveCount(1);
});

it('separates real missing clock-outs from overnight shifts the old view split', function () {
    // One 24h shift starting on the 5th only, so the 6th has no shift of its
    // own competing for the handover punch — which is how a real rota works,
    // with the next day belonging to the other bartender.
    AttendanceShiftTemplate::query()->update([
        'duration_minutes' => 1440,
        'weekly_days' => [CarbonImmutable::parse('2026-10-05')->isoWeekday()],
    ]);

    // A handover grace, so finishing five minutes before the hour is not
    // read as leaving early — that rule has its own tests, and this one is
    // about the calendar-day artefact.
    $this->settings->forceFill(['early_leave_grace_minutes' => 10])->save();

    staffed('7');

    // One complete shift: in on the 5th, out on the 6th.
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-06 07:55');

    $result = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-10-05'),
        CarbonImmutable::parse('2026-10-06'),
    );

    // The old calendar-day view sees two days with a single punch each and
    // calls both "no out time".
    expect($result['totals']['calendar_day_no_out_time'])->toBe(2);

    // The shift window sees what actually happened: one complete shift.
    expect($result['totals']['shift_window_no_clockout'])->toBe(0);
    expect($result['rows']->sole()['outcome'])->toBe('present');
});

it('warns when the range reaches into the hour-fast era', function () {
    staffed('7', from: '2026-09-01');

    $result = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
    );

    expect($result['warnings'])->not->toBeEmpty();
    expect($result['warnings'][0])->toContain('an hour fast');
});

it('does not warn for a range entirely after the fix', function () {
    staffed('7');

    $result = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-10-01'),
        CarbonImmutable::parse('2026-10-05'),
    );

    expect($result['warnings'])->toBe([]);
});

it('totals fines per staff member', function () {
    staffed('7');
    logPunch('7', '2026-10-05 09:00');
    logPunch('7', '2026-10-05 18:00');

    $result = app(AttendanceSimulator::class)->run(
        CarbonImmutable::parse('2026-10-05'),
        CarbonImmutable::parse('2026-10-05'),
    );

    $staff = $result['perStaff']->sole();
    expect($staff['scheduled'])->toBe(1);
    expect($staff['late'])->toBe(1);
    expect($staff['total_fines'])->toBe(500);
});

it('writes a three-sheet workbook and still saves nothing', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    logPunch('7', '2026-10-05 18:00');

    $path = sys_get_temp_dir().'/attendance-sim-test.xlsx';
    @unlink($path);

    $before = AttendanceShiftRecord::count() + AttendanceFine::count();

    $this->artisan('attendance:simulate', [
        '--from' => '2026-10-05',
        '--to' => '2026-10-05',
        '--output' => $path,
    ])->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $book = IOFactory::load($path);
    expect($book->getSheetCount())->toBe(3);
    expect($book->getSheet(0)->getTitle())->toBe('Per staff');
    expect($book->getSheet(1)->getTitle())->toBe('Every shift');
    expect($book->getSheet(2)->getTitle())->toBe('Totals');

    expect(AttendanceShiftRecord::count() + AttendanceFine::count())->toBe($before);

    @unlink($path);
});

it('says so plainly when the range produces no shifts', function () {
    $this->artisan('attendance:simulate --from=2026-10-05 --to=2026-10-05')
        ->expectsOutputToContain('No shifts were produced')
        ->assertSuccessful();
});
