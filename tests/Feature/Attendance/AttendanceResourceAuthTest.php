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
    'salary deductions' => '/admin/salary-deductions',
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
