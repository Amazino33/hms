<?php

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('lets a logged-in user set their own kiosk PIN from settings', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.pin')
        ->set('pin', '5739')
        ->set('pin_confirmation', '5739')
        ->call('updatePin')
        ->assertHasNoErrors();

    expect($user->fresh()->pin_hash)->not->toBeNull();
});

it('rejects a trivial PIN on the settings page with a visible error', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.pin')
        ->set('pin', '1234')
        ->set('pin_confirmation', '1234')
        ->call('updatePin')
        ->assertHasErrors('pin');

    expect($user->fresh()->pin_hash)->toBeNull();
});

it('rejects a mismatched PIN confirmation', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.pin')
        ->set('pin', '5739')
        ->set('pin_confirmation', '9999')
        ->call('updatePin')
        ->assertHasErrors('pin_confirmation');
});

it('lets a super_admin force-reset another user\'s PIN from the Users resource', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $staff = User::factory()->create();
    (new \App\Services\PinAuthService())->setPin($staff, '5739');
    expect($staff->fresh()->pin_hash)->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(\App\Filament\Resources\Users\Pages\ListUsers::class)
        ->callTableAction('forceResetPin', $staff);

    expect($staff->fresh()->pin_hash)->toBeNull();
});
