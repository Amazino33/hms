<?php

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use Database\Seeders\ShieldSeeder;

beforeEach(function () {
    $this->seed(ShieldSeeder::class);

    $this->day = AttendanceShiftTemplate::create([
        'name' => 'Day shift',
        'start_time' => '08:00:00',
        'duration_minutes' => 600,
        'pattern_type' => 'weekly',
        'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
    ]);

    $this->rotation = AttendanceShiftTemplate::create([
        'name' => 'Bartender 24h',
        'start_time' => '08:00:00',
        'duration_minutes' => 1440,
        'pattern_type' => 'rotation',
        'rotation_on_days' => 1,
        'rotation_off_days' => 1,
        'is_handover' => true,
    ]);
});

it('puts every trackable staff member on a weekly pattern', function () {
    User::factory()->count(3)->create();

    $this->artisan('attendance:assign-schedule', ['--all' => true, '--template' => 'Day shift', '--from' => '2026-10-01'])
        ->expectsConfirmation('Assign these 3 staff?', 'yes')
        ->assertSuccessful();

    expect(AttendanceShiftAssignment::count())->toBe(3);
});

it('refuses a rotation in bulk and says why', function () {
    User::factory()->create();

    // One anchor for everybody would put them all on the same days — which on
    // a handover rota means nobody covering the days between.
    $this->artisan('attendance:assign-schedule', ['--all' => true, '--template' => 'Bartender 24h', '--from' => '2026-10-01'])
        ->expectsOutputToContain('cannot be assigned in bulk')
        ->assertFailed();

    expect(AttendanceShiftAssignment::count())->toBe(0);
});

it('writes nothing on a dry run', function () {
    User::factory()->count(2)->create();

    $this->artisan('attendance:assign-schedule', ['--all' => true, '--template' => 'Day shift', '--from' => '2026-10-01', '--dry-run' => true])
        ->assertSuccessful();

    expect(AttendanceShiftAssignment::count())->toBe(0);
});

it('leaves alone anyone already scheduled on that date', function () {
    $scheduled = User::factory()->create(['name' => 'Already On']);
    User::factory()->create(['name' => 'Needs One']);

    AttendanceShiftAssignment::create([
        'user_id' => $scheduled->id,
        'attendance_shift_template_id' => $this->day->id,
        'effective_from' => '2026-09-01',
    ]);

    $this->artisan('attendance:assign-schedule', ['--all' => true, '--template' => 'Day shift', '--from' => '2026-10-01'])
        ->expectsConfirmation('Assign these 1 staff?', 'yes')
        ->assertSuccessful();

    expect(AttendanceShiftAssignment::count())->toBe(2);
    expect(AttendanceShiftAssignment::where('user_id', $scheduled->id)->count())->toBe(1);
});

it('skips exempt staff and leavers', function () {
    User::factory()->create(['name' => 'Normal']);
    User::factory()->create(['name' => 'Owner', 'attendance_exempt' => true]);
    User::factory()->create(['name' => 'Gone', 'left_at' => now()]);

    $this->artisan('attendance:assign-schedule', ['--all' => true, '--template' => 'Day shift', '--from' => '2026-10-01'])
        ->expectsConfirmation('Assign these 1 staff?', 'yes')
        ->assertSuccessful();

    expect(AttendanceShiftAssignment::count())->toBe(1);
});

it('can target one role', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    User::factory()->create();

    $this->artisan('attendance:assign-schedule', ['--role' => ['waiter'], '--template' => 'Day shift', '--from' => '2026-10-01'])
        ->expectsConfirmation('Assign these 1 staff?', 'yes')
        ->assertSuccessful();

    expect(AttendanceShiftAssignment::sole()->user_id)->toBe($waiter->id);
});

it('writes nothing when declined', function () {
    User::factory()->create();

    $this->artisan('attendance:assign-schedule', ['--all' => true, '--template' => 'Day shift', '--from' => '2026-10-01'])
        ->expectsConfirmation('Assign these 1 staff?', 'no')
        ->assertSuccessful();

    expect(AttendanceShiftAssignment::count())->toBe(0);
});

it('lists the templates when none is given', function () {
    $this->artisan('attendance:assign-schedule', ['--all' => true])
        ->expectsOutputToContain('--template is required')
        ->expectsOutputToContain('Day shift')
        ->assertFailed();
});
