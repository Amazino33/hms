<?php

use App\Models\RoomSupply;
use App\Models\RoomSupplyTransaction;
use App\Models\User;
use App\Models\WareHouse;
use Spatie\Permission\Models\Role;

it('shows the room supply catalog and cost to the ceo', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole(Role::firstOrCreate(['name' => 'ceo']));

    RoomSupply::create(['name' => 'Tissue Roll', 'unit' => 'roll', 'cost_per_unit' => 100]);

    $response = $this->actingAs($ceo)->get('/ceo/room-supplies');

    $response->assertSuccessful();
    $response->assertSee('Tissue Roll');
    $response->assertSee('100.00');
});

it('shows room supply stock movement to the ceo', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole(Role::firstOrCreate(['name' => 'ceo']));

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $supply = RoomSupply::create(['name' => 'Hand Soap', 'unit' => 'bar', 'cost_per_unit' => 200]);
    RoomSupplyTransaction::create([
        'room_supply_id' => $supply->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase',
        'quantity' => 10, 'cost_per_unit' => 200, 'user_id' => $ceo->id,
    ]);

    $response = $this->actingAs($ceo)->get('/ceo/room-supply-transactions');

    $response->assertSuccessful();
    $response->assertSee('Hand Soap');
    $response->assertSee('Main Store');
    $response->assertSee('Purchase');
});

it('blocks a plain waiter from both new ceo room supply resources', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($waiter)->get('/ceo/room-supplies')->assertForbidden();
    $this->actingAs($waiter)->get('/ceo/room-supply-transactions')->assertForbidden();
});
