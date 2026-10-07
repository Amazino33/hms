<?php

use App\Filament\Resources\DeviceUsers\DeviceUserResource;
use App\Filament\Resources\ShiftTemplates\ShiftTemplateResource;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * A Filament Resource whose model has no policy is open to every
 * authenticated panel user. That has already happened once in this codebase —
 * Daily Attendance lost its gate when its $model was swapped — so the
 * assertion is on the resource's actual model rather than on a class name
 * written here, and it follows a future swap instead of quietly testing the
 * wrong thing.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
});

dataset('attendance resources', [
    'shift templates' => ShiftTemplateResource::class,
    'device users' => DeviceUserResource::class,
]);

it('has a policy for the model each attendance resource points at', function (string $resource) {
    $model = $resource::getModel();

    expect(Gate::getPolicyFor($model))->not->toBeNull(
        "{$model} has no policy, so {$resource} is open to every panel user"
    );
})->with('attendance resources');

dataset('attendance resource urls', [
    'shift templates' => '/admin/shift-templates',
    'device users' => '/admin/device-users',
]);

it('blocks unprivileged staff from the attendance admin screens', function (string $url, string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get($url)->assertStatus(403);
})->with('attendance resource urls')->with(['receptionist', 'waiter', 'bartender', 'cashier', 'porter']);

it('lets super_admin reach the attendance admin screens', function (string $url) {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get($url)->assertStatus(200);
})->with('attendance resource urls');
