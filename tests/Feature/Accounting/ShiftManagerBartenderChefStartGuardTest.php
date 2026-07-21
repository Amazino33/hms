<?php

use App\Livewire\ShiftManager;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The Start Shift side of the same class of gap ShiftManagerBartenderChefGuardTest
 * covers for End Shift: bartender/chef shifts only ever start through a
 * reviewed opening count or a declared handover (BartenderChefShiftService),
 * never the generic button — that path has no idea another bartender/chef
 * already holds the shift, and no idea a handover count is in progress.
 * Asserted against the rendered markup, not just the PHP method, for the
 * same reason as the End Shift tests: a button that never calls into
 * Livewire at all would make a PHP-only guard invisible in testing.
 */
it('renders Go to My Handover Count instead of the generic Start Shift button for a bartender', function () {
    Role::firstOrCreate(['name' => 'bartender']);
    $bartender = User::factory()->create();
    $bartender->assignRole('bartender');

    Livewire::actingAs($bartender)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee('wire:click="goToHandoverCount"', false)
        ->assertDontSee("\$wire.call('startShift')", false);
});

it('renders Go to My Handover Count instead of the generic Start Shift button for a chef', function () {
    Role::firstOrCreate(['name' => 'chef']);
    $chef = User::factory()->create();
    $chef->assignRole('chef');

    Livewire::actingAs($chef)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee('wire:click="goToHandoverCount"', false)
        ->assertDontSee("\$wire.call('startShift')", false);
});

it('still renders the generic Start Shift button for a waiter, unaffected by the bartender/chef guard', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->assertSee("\$wire.call('startShift')", false)
        ->assertDontSee('wire:click="goToHandoverCount"', false);
});

it('rejects User::startShift() outright for a bartender with no active shift, directing them to My Handover Count', function () {
    Role::firstOrCreate(['name' => 'bartender']);
    $bartender = User::factory()->create();
    $bartender->assignRole('bartender');

    expect(fn () => $bartender->startShift())
        ->toThrow(Exception::class, 'Bartender shifts can only start through a reviewed opening count or a declared handover — use My Handover Count, not this control.');

    expect(Shift::where('user_id', $bartender->id)->exists())->toBeFalse();
});

it('rejects User::startShift() outright for a chef with no active shift', function () {
    Role::firstOrCreate(['name' => 'chef']);
    $chef = User::factory()->create();
    $chef->assignRole('chef');

    expect(fn () => $chef->startShift())
        ->toThrow(Exception::class, 'Chef shifts can only start through a reviewed opening count or a declared handover — use My Handover Count, not this control.');
});

it('still lets User::startShift() work normally for a waiter', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $shift = $waiter->startShift();

    expect($shift->type)->toBe('waiter');
    expect($shift->status)->toBe('active');
});

it('blocks a second bartender from starting a shift through the generic control while the first is still active — the exact production incident this guard closes', function () {
    Role::firstOrCreate(['name' => 'bartender']);
    $bartenderA = User::factory()->create();
    $bartenderA->assignRole('bartender');
    Shift::create(['user_id' => $bartenderA->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $bartenderB = User::factory()->create();
    $bartenderB->assignRole('bartender');

    expect(fn () => $bartenderB->startShift())->toThrow(Exception::class);

    // Bartender A's shift is completely undisturbed by B's blocked attempt.
    expect(Shift::where('user_id', $bartenderA->id)->where('status', 'active')->exists())->toBeTrue();
    expect(Shift::where('user_id', $bartenderB->id)->exists())->toBeFalse();
});

it('remains idempotent for a bartender who already has a matching active shift (double-click safety)', function () {
    Role::firstOrCreate(['name' => 'bartender']);
    $bartender = User::factory()->create();
    $bartender->assignRole('bartender');
    $existing = Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $returned = $bartender->startShift();

    expect($returned->id)->toBe($existing->id);
});
