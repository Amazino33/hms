<?php

use App\Filament\Pages\RoomSupplies;
use App\Models\PagePermission;
use App\Models\RoomSupply;
use App\Models\RoomSupplyTransaction;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('adds stock to an existing room supply and logs a purchase transaction', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $supply = RoomSupply::create(['name' => 'Tissue Roll', 'unit' => 'roll', 'cost_per_unit' => 90]);

    Livewire::actingAs($admin)
        ->test(RoomSupplies::class)
        ->callTableAction('add_stock', $supply, [
            'quantity' => 20,
            'cost_per_unit' => 100,
            'reference' => 'invoice',
            'reference_number' => 'INV-100',
        ]);

    expect((float) $supply->fresh()->current_stock)->toBe(20.0);
    expect((float) $supply->fresh()->cost_per_unit)->toBe(100.0);
    expect(RoomSupplyTransaction::where('room_supply_id', $supply->id)->where('type', 'purchase')->count())->toBe(1);
});

it('creates a new room supply catalog entry via the header action', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    Livewire::actingAs($admin)
        ->test(RoomSupplies::class)
        ->callTableAction('new_room_supply', null, [
            'name' => 'Hand Soap',
            'unit' => 'bar',
            'cost_per_unit' => 250,
        ]);

    expect(RoomSupply::where('name', 'Hand Soap')->exists())->toBeTrue();
});

it('lets a manager reach Room Supplies once the seeder grants it, and blocks a storekeeper-less waiter', function () {
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($manager)->get('/admin/room-supplies')->assertSuccessful();
    $this->actingAs($waiter)->get('/admin/room-supplies')->assertForbidden();
});

it('grants storekeeper and receptionist access to Room Supplies via the seeder', function () {
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);

    foreach (['storekeeper', 'receptionist', 'manager', 'super_admin'] as $role) {
        expect(
            PagePermission::where('page_class', \App\Filament\Pages\RoomSupplies::class)->where('role_name', $role)->exists()
        )->toBeTrue("Expected a '{$role}' grant for RoomSupplies.");
    }
});
