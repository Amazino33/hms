<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

it('renders the waiter ledger for a super admin', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get('/admin/waiter-ledger')
        ->assertStatus(200);
});

it('blocks a plain waiter from the waiter ledger', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)
        ->get('/admin/waiter-ledger')
        ->assertStatus(403);
});
