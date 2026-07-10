<?php

use App\Livewire\ShiftManager;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;

/**
 * showEndShiftDeclaration() used to open the generic cash/POS declaration
 * modal for every role — a bartender/chef would fill in numbers (that mean
 * nothing to them; they don't handle cash) only to have User::endShift()
 * reject the whole thing at the final "Confirm & End" step. It now sends
 * them straight into the counting flow instead — the actual page that
 * lets them count what they're handing over — rather than a dead-end
 * error pointing at it.
 */
it('sends a bartender straight into My Handover Count instead of opening the cash/POS declaration modal', function () {
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($bartender)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('showEndShiftDeclaration')
        ->assertSet('showDeclarationModal', false)
        ->assertRedirect('/admin/my-count');
});

it('sends a chef straight into My Handover Count instead of opening the cash/POS declaration modal', function () {
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($chef)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('showEndShiftDeclaration')
        ->assertSet('showDeclarationModal', false)
        ->assertRedirect('/admin/my-count');
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
