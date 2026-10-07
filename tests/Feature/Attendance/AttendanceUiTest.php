<?php

use App\Filament\Pages\AttendanceSettingsPage;
use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Services\Attendance\AttendanceOnlyRoleService;
use Database\Seeders\AttendanceShiftTemplateSeeder;
use Database\Seeders\ShieldSeeder;

beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    $this->seed(AttendanceShiftTemplateSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);
});

it('seeds the two real venue patterns and nothing else', function () {
    $templates = AttendanceShiftTemplate::orderBy('name')->pluck('name');

    expect($templates->all())->toBe(['Bartender 24h', 'Day shift']);

    $day = AttendanceShiftTemplate::where('name', 'Day shift')->sole();
    expect($day->describeHours())->toBe('08:00 - 18:00');
    expect($day->weekly_days)->toBe([1, 2, 3, 4, 5, 6, 7]);

    $bar = AttendanceShiftTemplate::where('name', 'Bartender 24h')->sole();
    // 24 hours, so it ends at the same clock time the next day.
    expect($bar->describeHours())->toBe('08:00 - 08:00 (+1 day)');
    expect($bar->is_handover)->toBeTrue();
});

it('renders the shift templates screen', function () {
    $this->get('/admin/shift-templates')->assertOk()->assertSee('Bartender 24h');
});

it('renders the device users screen with the unmatched filter on by default', function () {
    AttendanceDeviceUser::create(['device_user_id' => '20', 'device_name' => 'Annie']);

    $this->get('/admin/device-users')->assertOk()->assertSee('Annie');
});

it('renders the attendance rules page for a super admin', function () {
    $this->get('/admin/attendance-rules')->assertOk()->assertSee('Shadow mode');
});

it('keeps the attendance rules page away from everyone else', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect(AttendanceSettingsPage::canAccess())->toBeTrue();

    $this->actingAs($manager);
    expect(AttendanceSettingsPage::canAccess())->toBeFalse();

    $this->get('/admin/attendance-rules')->assertStatus(403);
});

it('saves a new rules version from the page rather than editing the old one', function () {
    AttendanceSetting::create(['effective_from' => '2026-01-01', 'fine_late' => 500]);

    Livewire\Livewire::test(AttendanceSettingsPage::class)
        ->fillForm([
            'effective_from' => '2026-11-01',
            'grace_minutes' => 20,
            'duplicate_punch_window_minutes' => 30,
            'fine_late' => 750,
            'fine_late_relief' => 1000,
            'fine_early_leave' => 1500,
            'fine_no_clockout' => 1500,
            'fine_absent' => 3000,
            'shadow_mode' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AttendanceSetting::count())->toBe(2);
    expect(AttendanceSetting::whereDate('effective_from', '2026-01-01')->sole()->fine_late)->toBe(500);
    expect(AttendanceSetting::whereDate('effective_from', '2026-11-01')->sole()->fine_late)->toBe(750);
});

it('creates an attendance-only user from the staff type toggle', function () {
    Livewire\Livewire::test(\App\Filament\Resources\Users\Pages\CreateUser::class)
        ->fillForm([
            'name' => 'Grace Umoh',
            'email' => 'grace@example.test',
            'staff_type' => 'attendance_only',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'grace@example.test')->sole();

    expect($user->hasRole(User::ATTENDANCE_ONLY_ROLE))->toBeTrue();
    expect($user->password)->toBeNull();
    // And the whole point: no way into either panel.
    expect($user->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
});

it('converts an attendance-only user back to an app user on the same record', function () {
    $user = User::factory()->create(['name' => 'Grace Umoh']);
    AttendanceOnlyRoleService::makeAttendanceOnly($user);
    $originalId = $user->id;

    $waiterId = Spatie\Permission\Models\Role::findByName('waiter')->id;

    Livewire\Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $user->id])
        ->fillForm([
            'staff_type' => 'app',
            'roles' => [$waiterId],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->id)->toBe($originalId);
    expect($user->hasRole('waiter'))->toBeTrue();
    expect($user->hasRole(User::ATTENDANCE_ONLY_ROLE))->toBeFalse();
    expect(User::where('name', 'Grace Umoh')->count())->toBe(1);
});

it('does not offer attendance_only in the roles picker', function () {
    // It is exclusive, so it must only ever be reachable through the toggle.
    $component = Livewire\Livewire::test(\App\Filament\Resources\Users\Pages\CreateUser::class);

    $options = $component->instance()->form->getComponent('data.roles')?->getOptions() ?? [];

    expect(array_values($options))->not->toContain(User::ATTENDANCE_ONLY_ROLE);
});

it('lists a non-exempt unscheduled staff member in the widget and omits the exempt one', function () {
    $unscheduled = User::factory()->create(['name' => 'Needs A Schedule']);
    $exempt = User::factory()->create(['name' => 'The Owner', 'attendance_exempt' => true]);

    Livewire\Livewire::test(\App\Filament\Widgets\StaffWithoutScheduleWidget::class)
        ->assertCanSeeTableRecords(User::whereKey($unscheduled->id)->get())
        ->assertCanNotSeeTableRecords(User::whereKey($exempt->id)->get());
});
