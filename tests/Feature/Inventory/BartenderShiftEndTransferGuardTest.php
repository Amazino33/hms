<?php

use App\Filament\Pages\MyCount;
use App\Models\CountSession;
use App\Models\PagePermission;
use App\Models\Shift;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function grantMyCountPagePermissionsForTransferGuard(): void
{
    foreach (['bartender', 'chef'] as $role) {
        PagePermission::firstOrCreate(
            ['page_class' => MyCount::class, 'role_name' => $role],
            ['page_class' => MyCount::class, 'page_name' => 'My Handover Count', 'role_name' => $role]
        );
    }
}

function makeOnShiftBartender(): array
{
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $mainStore = WareHouse::firstOrCreate(['name' => 'Main Store'], ['type' => 'storage']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Shift::create([
        'user_id' => $bartender->id, 'type' => 'bartender',
        'started_at' => now()->subHours(2), 'status' => 'active',
    ]);

    return compact('bar', 'mainStore', 'bartender');
}

it('blocks a bartender from starting a count to end shift while a transfer to their warehouse is still unreceived', function () {
    grantMyCountPagePermissionsForTransferGuard();
    ['bar' => $bar, 'mainStore' => $mainStore, 'bartender' => $bartender] = makeOnShiftBartender();
    $storekeeper = User::factory()->create();

    StockTransfer::create([
        'transfer_number' => 'ST-GUARD-1', 'from_warehouse_id' => $mainStore->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'sent',
    ]);

    $witness = User::factory()->create();
    $witness->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->assertSee('1 unreceived transfer is')
        ->set('isClosing', true)
        ->set('incomingUserId', $witness->id)
        ->call('startCount');

    expect(CountSession::count())->toBe(0);
});

it('lets the bartender start their closing count once the transfer is fully received', function () {
    grantMyCountPagePermissionsForTransferGuard();
    ['bar' => $bar, 'mainStore' => $mainStore, 'bartender' => $bartender] = makeOnShiftBartender();
    $storekeeper = User::factory()->create();

    StockTransfer::create([
        'transfer_number' => 'ST-GUARD-2', 'from_warehouse_id' => $mainStore->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'received',
    ]);

    $witness = User::factory()->create();
    $witness->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->assertDontSee('unreceived transfer')
        ->set('isClosing', true)
        ->set('incomingUserId', $witness->id)
        ->call('startCount')
        ->assertRedirect();

    expect(CountSession::count())->toBe(1);
});

it('does not block a fresh solo opening count with no active shift, even with a pending transfer', function () {
    grantMyCountPagePermissionsForTransferGuard();
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $mainStore = WareHouse::firstOrCreate(['name' => 'Main Store'], ['type' => 'storage']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    $storekeeper = User::factory()->create();

    // No active shift for this bartender at all yet — pending transfers at
    // the warehouse shouldn't block simply opening the day.
    StockTransfer::create([
        'transfer_number' => 'ST-GUARD-3', 'from_warehouse_id' => $mainStore->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'sent',
    ]);

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->assertSee('Start Your Opening Count')
        ->call('startCount')
        ->assertRedirect();

    expect(CountSession::count())->toBe(1);
});
