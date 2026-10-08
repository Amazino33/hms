<?php

use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Filament\Facades\Filament;

/**
 * Who can see attendance, and who definitely cannot.
 *
 * The admin panel is gated by Shield permissions; the ceo panel is gated
 * entirely at User::canAccessPanel() and everything inside it is visible to
 * whoever gets in. Two different mechanisms, so both are asserted.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
});

dataset('admin attendance urls', [
    'board' => '/admin/attendance-board',
    'review queue' => '/admin/attendance-review',
    'monthly fines' => '/admin/attendance-monthly-fines',
    'shift templates' => '/admin/shift-templates',
    'device users' => '/admin/device-users',
]);

it('lets the admin role reach the attendance screens', function (string $url) {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get($url)->assertStatus(200);
})->with('admin attendance urls');

it('lets super_admin reach them too', function (string $url) {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user)->get($url)->assertStatus(200);
})->with('admin attendance urls');

it('still blocks unprivileged staff', function (string $url, string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get($url)->assertStatus(403);
})->with('admin attendance urls')->with(['waiter', 'cashier', 'receptionist']);

it('keeps the fine amounts to super_admin alone', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Setting what people are charged is not a delegated decision.
    $this->actingAs($admin)->get('/admin/attendance-rules')->assertStatus(403);

    $super = User::factory()->create();
    $super->assignRole('super_admin');
    $this->actingAs($super)->get('/admin/attendance-rules')->assertStatus(200);
});

it('shows attendance to the ceo in their own panel', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $this->actingAs($ceo)->get('/ceo/attendance')->assertStatus(200);
    $this->actingAs($ceo)->get('/ceo/attendance-fines')->assertStatus(200);
});

it('keeps the ceo out of the admin panel entirely', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    // The ceo panel is their whole world — the admin attendance screens stay
    // closed even though they can see the same figures through their own.
    expect($ceo->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    $this->actingAs($ceo)->get('/admin/attendance-board')->assertStatus(403);
});

it('keeps a waiter out of the ceo panel', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)->get('/ceo/attendance')->assertStatus(403);
});
