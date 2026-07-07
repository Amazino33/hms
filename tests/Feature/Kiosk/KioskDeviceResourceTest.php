<?php

use App\Filament\Resources\KioskDevices\KioskDeviceResource;
use App\Filament\Resources\KioskDevices\Pages\ListKioskDevices;
use App\Models\KioskDevice;
use App\Models\User;
use App\Services\KioskDeviceService;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('lets a super_admin view registered kiosks and revoke one', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $service = new KioskDeviceService();
    ['code' => $code] = $service->generateRegistrationCode($admin);
    ['device' => $device] = $service->registerDevice($code, 'Bar Kiosk 1');

    Livewire::actingAs($admin)
        ->test(ListKioskDevices::class)
        ->assertCanSeeTableRecords([$device])
        ->callTableAction('revoke', $device);

    expect($device->fresh()->isRevoked())->toBeTrue();
});

it('blocks a plain waiter from the registered kiosks page', function () {
    $this->seed(ShieldSeeder::class);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)
        ->get(KioskDeviceResource::getUrl('index'))
        ->assertStatus(403);
});
