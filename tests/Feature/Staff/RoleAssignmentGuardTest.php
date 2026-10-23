<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\User;
use App\Services\Attendance\AttendanceOnlyRoleService;
use App\Services\Attendance\DeviceLinkService;
use App\Services\PinAuthService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Staff 0A §3. Only holders of assign_staff_roles (super_admin, seeded)
 * change roles — checked server-side on every path, by submitting the
 * request directly rather than trusting the read-only field. And without
 * it nobody edits, resets the password of, or resets the PIN of a
 * super_admin or ceo account.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    AttendanceOnlyRoleService::role();

    $this->admin = User::factory()->create(['name' => 'Office Admin']);
    $this->admin->assignRole('admin');

    $this->super = User::factory()->create(['name' => 'Owner']);
    $this->super->assignRole('super_admin');

    $this->waiter = User::factory()->create(['name' => 'Ada Waiter']);
    $this->waiter->assignRole('waiter');
});

it('grants assign_staff_roles to super_admin only', function () {
    expect($this->super->can('assign_staff_roles'))->toBeTrue();
    expect($this->admin->can('assign_staff_roles'))->toBeFalse();

    $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'assign_staff_roles'))->pluck('name')->all();
    expect($holders)->toBe(['super_admin']);
});

it('ignores a role change an admin submits through the edit form', function () {
    $superAdminRoleId = Role::findByName('super_admin')->id;

    Livewire::actingAs($this->admin)
        ->test(EditUser::class, ['record' => $this->waiter->getRouteKey()])
        ->assertFormFieldIsDisabled('roles')
        ->set('data.roles', [(string) $superAdminRoleId])
        ->set('data.job_title', 'Senior waiter')
        ->call('save')
        ->assertHasNoFormErrors();

    // The rest of the save went through; only the role change did not.
    expect($this->waiter->fresh()->job_title)->toBe('Senior waiter');

    $roles = $this->waiter->fresh()->roles->pluck('name')->all();
    expect($roles)->toBe(['waiter']);
});

it('refuses an admin switching an existing person to attendance-only, and rolls the save back', function () {
    // Control: the same admin renaming the same person does save.
    Livewire::actingAs($this->admin)
        ->test(EditUser::class, ['record' => $this->waiter->getRouteKey()])
        ->set('data.name', 'Ada Renamed')
        ->call('save')
        ->assertHasNoFormErrors();
    expect($this->waiter->fresh()->name)->toBe('Ada Renamed');

    $this->waiter->refresh()->forceFill(['name' => 'Ada Waiter'])->save();

    Livewire::actingAs($this->admin)
        ->test(EditUser::class, ['record' => $this->waiter->getRouteKey()])
        ->set('data.staff_type', 'attendance_only')
        ->set('data.name', 'Renamed In Same Save')
        ->call('save')
        ->assertNotified('Only the super admin can change staff roles');

    $waiter = $this->waiter->fresh();
    expect($waiter->hasRole('waiter'))->toBeTrue();
    expect($waiter->hasRole(User::ATTENDANCE_ONLY_ROLE))->toBeFalse();
    expect($waiter->password)->not->toBeNull();
    // Rolled back as one: the rest of that save did not land either.
    expect($waiter->name)->toBe('Ada Waiter');
});

it('lets an admin create only an attendance-only person, whatever is submitted', function () {
    $superAdminRoleId = Role::findByName('super_admin')->id;

    Livewire::actingAs($this->admin)
        ->test(CreateUser::class)
        ->set('data.staff_type', 'app')
        ->fillForm([
            'name' => 'Sneaky Hire',
            'email' => 'sneaky@example.com',
            'password' => 'password',
            'base_salary' => 0,
        ])
        ->set('data.roles', [(string) $superAdminRoleId])
        ->call('create');

    $created = User::where('email', 'sneaky@example.com')->first();
    expect($created)->not->toBeNull();
    expect($created->roles->pluck('name')->all())->toBe([User::ATTENDANCE_ONLY_ROLE]);
    expect($created->password)->toBeNull();
});

it('lets a super admin give and change roles through the form', function () {
    $managerRoleId = Role::findByName('manager')->id;

    Livewire::actingAs($this->super)
        ->test(EditUser::class, ['record' => $this->waiter->getRouteKey()])
        ->set('data.roles', [(string) $managerRoleId])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->waiter->fresh()->roles->pluck('name')->all())->toBe(['manager']);
});

it('refuses role changes in the service itself for an actor without the permission', function () {
    expect(fn () => AttendanceOnlyRoleService::makeAttendanceOnly($this->waiter, $this->admin))
        ->toThrow(ValidationException::class);

    $cleaner = AttendanceOnlyRoleService::makeAttendanceOnly(User::factory()->create(), $this->super);
    expect(fn () => AttendanceOnlyRoleService::makeAppUser($cleaner->fresh(), ['super_admin'], $this->admin))
        ->toThrow(ValidationException::class);

    expect($this->waiter->fresh()->hasRole('waiter'))->toBeTrue();
    expect($cleaner->fresh()->hasRole(User::ATTENDANCE_ONLY_ROLE))->toBeTrue();
});

it('still lets an admin onboard a cleaner from an unmatched badge', function () {
    $badge = AttendanceDeviceUser::create(['device_user_id' => '77', 'device_name' => 'CLEANER']);

    app(DeviceLinkService::class)->createStaffAndLink($badge, 'Grace Cleaner', 'Cleaner', null, $this->admin);

    $grace = User::where('name', 'Grace Cleaner')->first();
    expect($grace->roles->pluck('name')->all())->toBe([User::ATTENDANCE_ONLY_ROLE]);
});

it('keeps an admin out of super admin and ceo accounts', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    foreach ([$this->super, $ceo] as $protected) {
        expect($this->admin->can('update', $protected))->toBeFalse();

        $this->actingAs($this->admin)
            ->get("/admin/users/{$protected->id}/edit")
            ->assertForbidden();
    }

    // An ordinary staff member is still theirs to edit.
    expect($this->admin->can('update', $this->waiter))->toBeTrue();
    $this->actingAs($this->admin)->get("/admin/users/{$this->waiter->id}/edit")->assertOk();
});

it('refuses an admin resetting the PIN of a super admin, from the table and the service', function () {
    $this->super->forceFill(['pin_hash' => Hash::make('7391'), 'pin_lookup_hash' => 'x'])->save();

    Livewire::actingAs($this->admin)
        ->test(ListUsers::class)
        ->assertTableActionHidden('forceResetPin', $this->super)
        ->assertTableActionHidden('edit', $this->super)
        ->assertTableActionVisible('forceResetPin', $this->waiter);

    expect(fn () => (new PinAuthService)->forceReset($this->super, $this->admin))
        ->toThrow(ValidationException::class);

    expect($this->super->fresh()->pin_hash)->not->toBeNull();
});

it('refuses an admin changing the password of a super admin through a direct save', function () {
    $before = $this->super->password;

    try {
        Livewire::actingAs($this->admin)
            ->test(EditUser::class, ['record' => $this->super->getRouteKey()])
            ->set('data.password', 'taken-over')
            ->call('save');
    } catch (\Throwable) {
        // The page refuses to mount for this record (403); either way the
        // password must be untouched.
    }

    expect($this->super->fresh()->password)->toBe($before);
});
