<?php

use App\Livewire\ShiftManager;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;

/**
 * showEndShiftDeclaration() used to open the generic cash/POS declaration
 * modal for every role — a bartender/chef would fill in numbers (that mean
 * nothing to them; they don't handle cash) only to have User::endShift()
 * reject the whole thing at the final "Confirm & End" step. This catches
 * it up front instead, before the modal even opens.
 */
it('refuses to open the cash/POS declaration modal for a bartender, pointing to the handover flow instead', function () {
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($bartender)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('showEndShiftDeclaration')
        ->assertSet('showDeclarationModal', false);
});

it('refuses to open the cash/POS declaration modal for a chef', function () {
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($chef)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('showEndShiftDeclaration')
        ->assertSet('showDeclarationModal', false);
});

it('still opens the cash/POS declaration modal for a waiter, unaffected by the bartender/chef guard', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('showEndShiftDeclaration')
        ->assertSet('showDeclarationModal', true);
});
