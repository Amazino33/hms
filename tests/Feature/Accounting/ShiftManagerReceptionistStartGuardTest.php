<?php

use App\Livewire\ShiftManager;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The receptionist side of the same class of gap
 * ShiftManagerBartenderChefStartGuardTest covers for bartender/chef: the
 * generic Start/End Shift controls have no field for a starting cash
 * float at all (ReceptionistShiftService), so a receptionist starting
 * through them would silently record a shift with no float, understating
 * expectedCashRemittance() by exactly that amount at settlement time.
 * Unlike bartender/chef, no handover-count system is needed — the front
 * desk's per-person cash reconciliation already happens through
 * declareEnd()/CashierSettlementService; this only closes the "wrong
 * button" gap and the single-custodian (one receptionist at a time) rule.
 */
it('renders Go to Receptionist Shift instead of the generic Start Shift button for a receptionist', function () {
    Role::firstOrCreate(['name' => 'receptionist']);
    $receptionist = User::factory()->create();
    $receptionist->assignRole('receptionist');

    Livewire::actingAs($receptionist)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee('wire:click="goToReceptionistShift"', false)
        ->assertDontSee("\$wire.call('startShift')", false);
});

it('still renders the generic Start Shift button for a waiter, unaffected by the receptionist guard', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee("\$wire.call('startShift')", false)
        ->assertDontSee('wire:click="goToReceptionistShift"', false);
});

it('rejects User::startShift() outright for a receptionist with no active shift, directing them to the Receptionist Shift page', function () {
    Role::firstOrCreate(['name' => 'receptionist']);
    $receptionist = User::factory()->create();
    $receptionist->assignRole('receptionist');

    expect(fn () => $receptionist->startShift())
        ->toThrow(Exception::class, 'Receptionist shifts start from the Receptionist Shift page, where your starting cash float is recorded — use that, not this control.');

    expect(Shift::where('user_id', $receptionist->id)->exists())->toBeFalse();
});

it('renders Go to Receptionist Shift instead of the generic End Shift button while on an active receptionist shift', function () {
    Role::firstOrCreate(['name' => 'receptionist']);
    $receptionist = User::factory()->create();
    $receptionist->assignRole('receptionist');
    Shift::create(['user_id' => $receptionist->id, 'type' => 'receptionist', 'starting_float' => 5000, 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($receptionist)
        ->test(ShiftManager::class)
        ->call('load')
        // The Owner's Take modal markup is always in the DOM (hidden via
        // Alpine x-show, not a Blade conditional) — the actual guard is
        // that its trigger button never renders for a receptionist.
        ->assertSee('wire:click="goToReceptionistShift"', false)
        ->assertDontSee('@click="showOwnerTakeModal = true"', false);
});
