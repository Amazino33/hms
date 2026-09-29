<?php

use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Spatie\Permission\Models\Role;

/**
 * Attendance logs and salary deductions are payroll records: they were
 * visible to every authenticated panel user (a receptionist saw them in
 * the sidebar) because a Filament Resource with no generated policy is
 * wide open — unlike PagePermission-gated pages, which deny by default.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
});

dataset('payroll resource urls', [
    'attendance logs' => '/admin/attendance-logs',
    'surcharges' => '/admin/surcharges',
]);

dataset('unprivileged roles', ['receptionist', 'waiter', 'bartender', 'chef', 'storekeeper', 'cashier', 'porter']);

it('blocks unprivileged staff from payroll resources', function (string $url, string $roleName) {
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    $this->actingAs($user)
        ->get($url)
        ->assertStatus(403);
})->with('payroll resource urls')->with('unprivileged roles');

it('allows super_admin to view payroll resources', function (string $url) {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get($url)
        ->assertStatus(200);
})->with('payroll resource urls');

/**
 * The gate moved once already without anyone noticing: switching the Daily
 * Attendance resource's model from AttendanceLog to the DailyAttendance
 * database view took it out from behind AttendanceLogPolicy, because Laravel
 * resolves policies by model class — and a resource with no policy is wide
 * open. That silently undid this whole file for /admin/attendance-logs.
 */
it('gates the attendance page on the model the resource actually points at', function () {
    $model = \App\Filament\Resources\AttendanceLogs\AttendanceLogResource::getModel();

    expect(\Illuminate\Support\Facades\Gate::getPolicyFor($model))->not->toBeNull(
        "{$model} has no policy, so the attendance page is open to every panel user"
    );
});

it('still lets a ceo-role user reach their own attendance page', function () {
    // The admin panel's policy resolves by model class, so it reaches into
    // the ceo panel too; CeoReadOnlyResource is what stops it denying a user
    // who correctly holds no admin Shield permissions.
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $this->actingAs($ceo)
        ->get('/ceo/attendance-logs')
        ->assertStatus(200);
});
