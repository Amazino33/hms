<?php

use App\Livewire\ShiftManager;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;

/**
 * The "End Shift" button used to be pure Alpine (@click="showDeclarationModal
 * = true") for every role — no wire:click at all — so two earlier attempts
 * to gate this in PHP alone never actually took effect for bartenders/chefs:
 * nothing on the button called into Livewire to begin with. These tests
 * assert on the rendered markup itself, not just the PHP method in
 * isolation, specifically to catch that class of regression again.
 */
it('renders the End Shift button wired to goToHandoverCount for a bartender, not the Alpine-only declaration modal', function () {
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($bartender)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee('wire:click="goToHandoverCount"', false)
        ->assertDontSee('showDeclarationModal = true', false);
});

it('renders the End Shift button wired to goToHandoverCount for a chef', function () {
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($chef)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee('wire:click="goToHandoverCount"', false)
        ->assertDontSee('showDeclarationModal = true', false);
});

it('still renders the Alpine-only declaration modal trigger for a waiter, unaffected by the bartender/chef guard', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee('showDeclarationModal = true', false)
        ->assertDontSee('wire:click="goToHandoverCount"', false);
});

it('redirects to My Handover Count when goToHandoverCount is called', function () {
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($bartender)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('goToHandoverCount')
        ->assertRedirect('/admin/my-count');
});
