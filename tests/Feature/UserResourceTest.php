<?php

use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can render the create user page without a record', function () {
    $this->seed(ShieldSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get('/admin/users/create')
        ->assertStatus(200);
});
it('can render the edit user page for an existing record', function () {
    $this->seed(ShieldSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $target = User::factory()->create();

    $this->actingAs($admin)
        ->get("/admin/users/{$target->id}/edit")
        ->assertStatus(200);
});

it('can render the edit page for a staff member who has commissions', function () {
    $this->seed(ShieldSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $waiter = User::factory()->create([
        'staff_code' => 'LH-023',
        'biometric_id' => '23',
        'shift_start_time' => '08:00:00',
    ]);

    $order = \App\Models\Order::factory()->create();
    \App\Models\Commission::create([
        'user_id' => $waiter->id,
        'order_id' => $order->id,
        'amount' => 1500.00,
    ]);

    $this->actingAs($admin)
        ->get("/admin/users/{$waiter->id}/edit")
        ->assertStatus(200);
});
