<?php

use App\Filament\Pages\PendingCashDrops;
use App\Models\CashDrop;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('declares a cash drop through the pos component', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    Livewire::actingAs($waiter)
        ->test('pos')
        ->call('openCashDropModal')
        ->set('cashDropReceiverId', $manager->id)
        ->set('cashDropAmount', 15000)
        ->set('cashDropNote', 'End of lunch rush')
        ->call('declareCashDrop')
        ->assertSet('showCashDropModal', false);

    $drop = CashDrop::first();
    expect($drop)->not->toBeNull();
    expect($drop->waiter_id)->toBe($waiter->id);
    expect($drop->received_by)->toBe($manager->id);
    expect((float) $drop->declared_amount)->toEqual(15000.0);
    expect($drop->status)->toBe('pending');
});

it('shows only drops addressed to the logged-in manager on the pending cash drops page', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    Role::firstOrCreate(['name' => 'manager']);

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $managerA = User::factory()->create();
    $managerA->assignRole(['manager', 'super_admin']);
    $managerB = User::factory()->create();
    $managerB->assignRole(['manager', 'super_admin']);

    $dropToA = CashDrop::create(['waiter_id' => $waiter->id, 'received_by' => $managerA->id, 'shift_id' => $waiter->currentShift()->id, 'declared_amount' => 5000, 'status' => 'pending']);
    $dropToB = CashDrop::create(['waiter_id' => $waiter->id, 'received_by' => $managerB->id, 'shift_id' => $waiter->currentShift()->id, 'declared_amount' => 7000, 'status' => 'pending']);

    Livewire::actingAs($managerA)
        ->test(PendingCashDrops::class)
        ->assertCanSeeTableRecords([$dropToA])
        ->assertCanNotSeeTableRecords([$dropToB]);
});

it('confirms a drop from the pending cash drops page', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    Role::firstOrCreate(['name' => 'manager']);

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole(['manager', 'super_admin']);

    $drop = CashDrop::create(['waiter_id' => $waiter->id, 'received_by' => $manager->id, 'shift_id' => $waiter->currentShift()->id, 'declared_amount' => 5000, 'status' => 'pending']);

    Livewire::actingAs($manager)
        ->test(PendingCashDrops::class)
        ->callTableAction('confirm', $drop);

    expect($drop->fresh()->status)->toBe('confirmed');
    expect((float) $drop->fresh()->confirmed_amount)->toEqual(5000.0);
});
