<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

it('lets a ceo-role user access the CEO panel', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $user = User::factory()->create();
    $user->assignRole('ceo');

    $this->actingAs($user)->get('/ceo')->assertSuccessful();
});

it('blocks a ceo-role user from the admin panel', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $user = User::factory()->create();
    $user->assignRole('ceo');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('blocks a user with no roles from the CEO panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/ceo')->assertForbidden();
});

it('blocks an existing staff role (waiter) from the CEO panel', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $user = User::factory()->create();
    $user->assignRole('waiter');

    $this->actingAs($user)->get('/ceo')->assertForbidden();
});

it('does not let a waiter role lose their own admin panel access after the ceo panel was introduced', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $user = User::factory()->create();
    $user->assignRole('waiter');

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

it('lets super_admin access both the admin and ceo panels', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user)->get('/ceo')->assertSuccessful();
    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

it('every discovered CEO page loads successfully for a ceo user', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $user = User::factory()->create();
    $user->assignRole('ceo');

    $paths = [
        '/ceo', '/ceo/daily-digest', '/ceo/waiter-ledger', '/ceo/sales-report',
        '/ceo/occupancy-report', '/ceo/leakage-report', '/ceo/stock-alerts',
        '/ceo/orders', '/ceo/waiter-shift-settlements', '/ceo/receptionist-shift-settlements',
        '/ceo/folios', '/ceo/reservations', '/ceo/handover-counts', '/ceo/staff-debts',
        '/ceo/inventory-transactions', '/ceo/procurements', '/ceo/report-explorer',
    ];

    foreach ($paths as $path) {
        $this->actingAs($user)->get($path)->assertSuccessful();
    }
});
