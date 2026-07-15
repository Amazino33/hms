<?php

use App\Filament\Pages\PendingCashDrops;
use App\Models\CashDrop;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Rewritten for the cashier module: a drop no longer names a recipient at
 * declare time, and PendingCashDrops is a shared queue (any eligible
 * role can see/confirm any pending drop), not a per-manager inbox.
 */
it('declares a cash drop through the pos component', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->call('openCashDropModal')
        ->set('cashDropAmount', 15000)
        ->set('cashDropNote', 'End of lunch rush')
        ->call('declareCashDrop')
        ->assertSet('showCashDropModal', false);

    $drop = CashDrop::first();
    expect($drop)->not->toBeNull();
    expect($drop->waiter_id)->toBe($waiter->id);
    expect($drop->received_by)->toBeNull();
    expect((float) $drop->declared_amount)->toEqual(15000.0);
    expect($drop->status)->toBe('pending');
});

it('shows every pending drop on the shared queue, regardless of who eventually confirms it', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    Role::firstOrCreate(['name' => 'manager']);

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $managerA = User::factory()->create();
    $managerA->assignRole(['manager', 'super_admin']);

    $dropOne = CashDrop::create(['waiter_id' => $waiter->id, 'received_by' => null, 'shift_id' => $waiter->currentShift()->id, 'declared_amount' => 5000, 'status' => 'pending']);
    $dropTwo = CashDrop::create(['waiter_id' => $waiter->id, 'received_by' => null, 'shift_id' => $waiter->currentShift()->id, 'declared_amount' => 7000, 'status' => 'pending']);
    $alreadyConfirmed = CashDrop::create(['waiter_id' => $waiter->id, 'received_by' => $managerA->id, 'shift_id' => $waiter->currentShift()->id, 'declared_amount' => 3000, 'confirmed_amount' => 3000, 'status' => 'confirmed', 'confirmed_at' => now()]);

    Livewire::actingAs($managerA)
        ->test(PendingCashDrops::class)
        ->assertCanSeeTableRecords([$dropOne, $dropTwo, $alreadyConfirmed]);
});

it('confirms a drop from the pending cash drops page', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    Role::firstOrCreate(['name' => 'manager']);

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole(['manager', 'super_admin']);

    $drop = CashDrop::create(['waiter_id' => $waiter->id, 'received_by' => null, 'shift_id' => $waiter->currentShift()->id, 'declared_amount' => 5000, 'status' => 'pending']);

    Livewire::actingAs($manager)
        ->test(PendingCashDrops::class)
        ->callTableAction('confirm', $drop);

    expect($drop->fresh()->status)->toBe('confirmed');
    expect($drop->fresh()->received_by)->toBe($manager->id);
    expect((float) $drop->fresh()->confirmed_amount)->toEqual(5000.0);
});
