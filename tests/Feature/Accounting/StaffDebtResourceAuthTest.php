<?php

use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Spatie\Permission\Models\Role;

it('blocks a plain waiter from the staff debts resource', function () {
    $this->seed(ShieldSeeder::class);
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)
        ->get('/admin/staff-debts')
        ->assertStatus(403);
});

it('allows a manager to view the staff debts resource', function () {
    $this->seed(ShieldSeeder::class);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $this->actingAs($manager)
        ->get('/admin/staff-debts')
        ->assertStatus(200);
});

it('allows super_admin to view the staff debts resource', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get('/admin/staff-debts')
        ->assertStatus(200);
});
