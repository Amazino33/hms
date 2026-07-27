<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

it('renders the sidebar navigation search box for a logged-in admin user', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertSuccessful();
    $response->assertSee('fi-sidebar-nav-search-input', false);
    $response->assertSee('Search menu', false);
});
