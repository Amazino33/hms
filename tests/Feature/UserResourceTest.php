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