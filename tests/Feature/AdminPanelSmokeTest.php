<?php

use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ShieldSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

it('renders index and create pages for resources touched during the audit', function (string $path) {
    $this->actingAs($this->admin)
        ->get($path)
        ->assertStatus(200);
})->with([
    '/admin/warehouses',
    '/admin/warehouses/create',
    '/admin/suppliers',
    '/admin/suppliers/create',
    '/admin/guests',
    '/admin/guests/create',
    '/admin/orders',
    '/admin/production-orders',
    '/admin/production-orders/create',
    '/admin/rooms',
    '/admin/rooms/create',
    '/admin/bookings',
    '/admin/bookings/create',
]);

it('no longer exposes a manual order-create page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/orders/create')
        ->assertStatus(404);
});