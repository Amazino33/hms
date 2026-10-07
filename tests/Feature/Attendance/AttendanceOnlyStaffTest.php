<?php

use App\Models\User;
use App\Services\Attendance\AttendanceExemptionService;
use App\Services\Attendance\AttendanceOnlyRoleService;
use App\Services\PayrollCompilationService;
use App\Services\PinAuthService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    AttendanceOnlyRoleService::role();
});

function attendanceOnlyUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    return AttendanceOnlyRoleService::makeAttendanceOnly($user);
}

it('keeps an attendance-only user out of every filament panel', function () {
    $user = attendanceOnlyUser();

    // The trap this guards: canAccessPanel() grants access to any user with
    // at least one role, and attendance_only IS a role.
    $this->actingAs($user)->get('/admin')->assertStatus(403);
    $this->actingAs($user)->get('/ceo')->assertStatus(403);
});

it('cannot authenticate on the kiosk with no pin', function () {
    $user = attendanceOnlyUser();

    expect($user->pin_hash)->toBeNull();
    expect($user->pin_lookup_hash)->toBeNull();

    // Tested against the real service, unmodified: a NULL lookup hash can
    // never equal the hash of anything somebody types.
    $service = app(PinAuthService::class);

    foreach (['1357', '2468', '9753'] as $guess) {
        expect($service->attempt($guess, 'kiosk-test-'.$guess))->toBeNull();
    }
});

it('converts to an app role on the same record, never a second one', function () {
    $user = attendanceOnlyUser(['name' => 'Grace Umoh']);
    $originalId = $user->id;

    AttendanceOnlyRoleService::makeAppUser($user, ['waiter']);

    expect($user->fresh()->id)->toBe($originalId);
    expect(User::where('name', 'Grace Umoh')->count())->toBe(1);
    expect($user->fresh()->hasRole('waiter'))->toBeTrue();
    expect($user->fresh()->hasRole(User::ATTENDANCE_ONLY_ROLE))->toBeFalse();
});

it('lets a converted app user back into the panel', function () {
    $user = attendanceOnlyUser();
    $this->actingAs($user)->get('/admin')->assertStatus(403);

    AttendanceOnlyRoleService::makeAppUser($user, ['waiter']);

    $this->actingAs($user->fresh())->get('/admin')->assertStatus(200);
});

it('strips every other role when making someone attendance-only', function () {
    $user = User::factory()->create();
    $user->assignRole(['waiter', 'cashier']);

    AttendanceOnlyRoleService::makeAttendanceOnly($user);

    expect($user->fresh()->roles->pluck('name')->all())->toBe([User::ATTENDANCE_ONLY_ROLE]);
});

it('clears credentials so the record cannot authenticate at all', function () {
    $user = User::factory()->create();
    app(PinAuthService::class)->setPin($user, '5283');
    expect($user->fresh()->pin_hash)->not->toBeNull();

    AttendanceOnlyRoleService::makeAttendanceOnly($user->fresh());

    $user->refresh();
    expect($user->password)->toBeNull();
    expect($user->pin_hash)->toBeNull();
    expect(app(PinAuthService::class)->attempt('5283', 'kiosk-after-convert'))->toBeNull();
});

it('rejects attendance_only held alongside any other role', function () {
    expect(fn () => AttendanceOnlyRoleService::assertExclusive([User::ATTENDANCE_ONLY_ROLE, 'waiter']))
        ->toThrow(ValidationException::class);

    // On its own it is fine, and so is any combination that excludes it.
    $allowed = function (array $roles): bool {
        AttendanceOnlyRoleService::assertExclusive($roles);

        return true;
    };

    expect($allowed([User::ATTENDANCE_ONLY_ROLE]))->toBeTrue();
    expect($allowed(['waiter', 'cashier']))->toBeTrue();
});

it('refuses to convert to an app user with no role at all', function () {
    $user = attendanceOnlyUser();

    expect(fn () => AttendanceOnlyRoleService::makeAppUser($user, []))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->hasRole(User::ATTENDANCE_ONLY_ROLE))->toBeTrue();
});

it('grants the attendance_only role no permissions whatsoever', function () {
    expect(Role::findByName(User::ATTENDANCE_ONLY_ROLE)->permissions)->toHaveCount(0);
});

it('lets only a super admin change attendance exemption', function () {
    $target = User::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect(fn () => AttendanceExemptionService::set($target, true, $manager))
        ->toThrow(ValidationException::class);

    expect($target->fresh()->attendance_exempt)->toBeFalse();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    AttendanceExemptionService::set($target, true, $admin);

    expect($target->fresh()->attendance_exempt)->toBeTrue();
});

it('logs who changed an attendance exemption and what it was before', function () {
    $target = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    AttendanceExemptionService::set($target, true, $admin);

    $log = Activity::where('log_name', 'user')
        ->where('subject_id', $target->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->causer_id)->toBe($admin->id);
    expect($log->properties['old']['attendance_exempt'])->toBeFalse();
    expect($log->properties['attributes']['attendance_exempt'])->toBeTrue();
});

it('cannot have attendance_exempt set by mass assignment', function () {
    $user = User::factory()->create();

    $user->fill(['attendance_exempt' => true]);
    $user->save();

    // Not fillable on purpose — AttendanceExemptionService is the only writer,
    // so a form that happens to accept this key changes nothing.
    expect($user->fresh()->attendance_exempt)->toBeFalse();
});

/**
 * Documents a known, deliberate Phase 1 gap rather than asserting a desired
 * end state: attendance-only staff are invisible to payroll because
 * PayrollCompilationService selects by role. Phase 3 adds them and flips this
 * test — until then it stops the gap being discovered by a cleaner not being
 * paid.
 */
it('does not yet include attendance-only staff in payroll (phase 3 will)', function () {
    $cleaner = attendanceOnlyUser(['name' => 'Cleaner']);
    $waiter = User::factory()->create(['name' => 'Waiter']);
    $waiter->assignRole('waiter');

    $eligible = app(PayrollCompilationService::class)->eligibleStaff()->pluck('id');

    expect($eligible)->toContain($waiter->id);
    expect($eligible)->not->toContain($cleaner->id);
});
