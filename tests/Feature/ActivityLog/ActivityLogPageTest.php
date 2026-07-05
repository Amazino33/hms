<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

it('renders the activity log page for a super admin', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get('/admin/activity-log-page')
        ->assertStatus(200);
});

it('blocks a plain waiter from the activity log page by default', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)
        ->get('/admin/activity-log-page')
        ->assertStatus(403);
});
