<?php

require_once __DIR__.'/AttendanceTestHelpers.php';

use App\Filament\Pages\AttendanceBoard;
use App\Filament\Pages\AttendanceReviewQueue;
use App\Filament\Pages\AttendanceSettingsPage;
use App\Filament\Pages\MonthlyFinesReport;
use App\Models\Attendance\AttendanceReviewItem;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

beforeEach(function () {
    config(['attendance.engine_start_date' => '2026-10-01']);
    $this->seed(ShieldSeeder::class);

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

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);
});

it('renders the attendance board', function () {
    $this->get('/admin/attendance-board')->assertOk();
});

it('shows the judged shifts for the chosen date with their tiles', function () {
    $user = staffed('7');
    logPunch('7', '2026-10-05 09:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $component = Livewire::test(AttendanceBoard::class)->set('date', '2026-10-05');

    $tiles = $component->instance()->getTiles();
    expect($tiles['scheduled']['value'])->toBe(1);
    expect($tiles['late']['value'])->toBe(1);
    expect($tiles['present']['value'])->toBe(0);

    $component->assertCanSeeTableRecords(AttendanceShiftRecord::all());
});

it('separates fines from withheld pay on the board', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $record = AttendanceShiftRecord::sole();

    // ₦3,000 fine and ₦2,500 withheld pay are different things and must never
    // appear as one ₦5,500 figure.
    expect($record->fines->where('kind', 'fine')->sum('amount'))->toBe(3000);
    expect($record->fines->where('kind', 'pay_deduction')->sum('amount'))->toBe(2500);
});

it('lets a super admin re-evaluate a date from the board', function () {
    staffed('7');
    logPunch('7', '2026-10-05 08:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    expect(AttendanceShiftRecord::current()->sole()->outcome)->toBe('no_clockout');

    logPunch('7', '2026-10-05 18:00');

    Livewire::test(AttendanceBoard::class)
        ->set('date', '2026-10-05')
        ->callTableAction('reevaluate', data: ['reason' => 'backlog arrived']);

    expect(AttendanceShiftRecord::current()->sole()->outcome)->toBe('present');
});

it('hides re-evaluate from anyone who is not a super admin', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $manager->givePermissionTo('ViewAny:AttendanceShiftTemplate');
    $this->actingAs($manager);

    Livewire::test(AttendanceBoard::class)->assertTableActionHidden('reevaluate');
});

it('renders the review queue and marks an item reviewed', function () {
    $item = AttendanceReviewItem::create([
        'shift_date' => '2026-10-05',
        'reason' => AttendanceReviewItem::SUSPECTED_OUTAGE,
        'detail' => '4 of 5 staff absent.',
    ]);

    $this->get('/admin/attendance-review')->assertOk();

    Livewire::test(AttendanceReviewQueue::class)
        ->callTableAction('markReviewed', $item);

    $item->refresh();
    expect($item->resolved_at)->not->toBeNull();
    expect($item->resolved_by)->toBe($this->admin->id);
});

it('renders the monthly fines report split shadow from live', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $this->get('/admin/attendance-monthly-fines')->assertOk();

    $component = Livewire::test(MonthlyFinesReport::class)->set('month', '2026-10');

    $totals = $component->instance()->totals();
    expect($totals['shadow_fines'])->toBe(3000);
    expect($totals['shadow_pay'])->toBe(2500);
    // Nothing is live while the master switch is off.
    expect($totals['live_fines'])->toBe(0);
});

it('exports the monthly fines as a workbook', function () {
    staffed('7');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $response = Livewire::test(MonthlyFinesReport::class)
        ->set('month', '2026-10')
        ->instance()
        ->export();

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    // A .xlsx is a zip; PK is its signature.
    expect(substr($body, 0, 2))->toBe('PK');
});

it('shows the shadow banner while live fines are switched off', function () {
    $this->get('/admin/attendance-rules')
        ->assertOk()
        ->assertSee('All fines are shadow')
        ->assertSee('Live fines are disabled');
});

it('refuses a rules start date that is not in the future', function () {
    $yesterday = \Carbon\CarbonImmutable::now(\App\Support\VenueTime::TIMEZONE)->subDay()->toDateString();

    Livewire::test(AttendanceSettingsPage::class)
        ->fillForm([
            'effective_from' => \Carbon\CarbonImmutable::now()->toDateString(),
            'grace_minutes' => 15,
            'early_leave_grace_minutes' => 0,
            'duplicate_punch_window_minutes' => 30,
            'window_before_minutes' => 120,
            'window_after_minutes' => 240,
            'finalise_delay_minutes' => 60,
            'max_device_wait_minutes' => 1440,
            'fine_late' => 500,
            'fine_late_relief' => 1000,
            'fine_early_leave' => 1500,
            'fine_no_clockout' => 1500,
            'fine_absent' => 3000,
            'shadow_mode' => true,
            // Backdating the announcement would charge people for days they
            // were never told about.
            'rules_start_date' => $yesterday,
        ])
        ->call('save')
        // The page uses statePath('data'), so the error surfaces under that key.
        ->assertHasErrors('data.rules_start_date');

    // And nothing was written.
    expect(AttendanceSetting::count())->toBe(1);
});

it('accepts a rules start date from tomorrow', function () {
    $tomorrow = \Carbon\CarbonImmutable::now(\App\Support\VenueTime::TIMEZONE)->addDay()->toDateString();

    Livewire::test(AttendanceSettingsPage::class)
        ->fillForm([
            'effective_from' => \Carbon\CarbonImmutable::now()->addDays(2)->toDateString(),
            'grace_minutes' => 15,
            'early_leave_grace_minutes' => 10,
            'duplicate_punch_window_minutes' => 30,
            'window_before_minutes' => 120,
            'window_after_minutes' => 240,
            'finalise_delay_minutes' => 60,
            'max_device_wait_minutes' => 1440,
            'fine_late' => 500,
            'fine_late_relief' => 1000,
            'fine_early_leave' => 1500,
            'fine_no_clockout' => 1500,
            'fine_absent' => 3000,
            'shadow_mode' => true,
            'rules_start_date' => $tomorrow,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AttendanceSetting::count())->toBe(2);
    expect(AttendanceSetting::orderByDesc('id')->first()->early_leave_grace_minutes)->toBe(10);
});

it('shows a staff member their judged shifts on their own page', function () {
    $user = staffed('7');
    logPunch('7', '2026-10-05 09:00');
    logPunch('7', '2026-10-05 18:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    Livewire::test(\App\Filament\Resources\Users\RelationManagers\AttendanceRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => \App\Filament\Resources\Users\Pages\EditUser::class,
    ])->assertCanSeeTableRecords(AttendanceShiftRecord::where('user_id', $user->id)->get());
});

it('hides superseded records from a staff member page', function () {
    $user = staffed('7');
    logPunch('7', '2026-10-05 08:00');
    deviceHeardAt('2026-10-05 23:30');
    finaliseAt('2026-10-05 23:30');

    $superseded = AttendanceShiftRecord::sole();

    logPunch('7', '2026-10-05 18:00');
    app(\App\Services\Attendance\ShiftReevaluationService::class)->reevaluate(
        \Carbon\CarbonImmutable::parse('2026-10-05'),
        \Carbon\CarbonImmutable::parse('2026-10-05'),
        'backlog',
    );

    // The old judgement is kept but must not be shown as if it still stands.
    Livewire::test(\App\Filament\Resources\Users\RelationManagers\AttendanceRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => \App\Filament\Resources\Users\Pages\EditUser::class,
    ])
        ->assertCanNotSeeTableRecords(AttendanceShiftRecord::whereKey($superseded->id)->get())
        ->assertCanSeeTableRecords(AttendanceShiftRecord::current()->get());
});
