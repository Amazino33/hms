<?php

use App\Models\Table as TableModel;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Multiple browser tabs can share the same staff_pin session (e.g. two
 * Incognito tabs on one kiosk, or a waiter's phone left open on two tabs).
 * Completing an order or an inactivity timeout in ANY tab logs the shared
 * staff_pin guard out for all of them. Before this fix, the next wire:poll
 * tick (loadCurrentShift, every 10s) or click on any other still-open tab
 * would hard-crash on auth()->user()->currentShift() instead of bouncing
 * back to the PIN pad.
 */
it('redirects to the kiosk table grid instead of crashing when the staff_pin session dies mid-poll on a kiosk device', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => 1]);

    $component = Livewire::test('pos', ['table_id' => $table->id]);

    // Simulate another tab's order completing and logging the shared
    // staff_pin session out from underneath this still-open tab.
    Auth::guard('staff_pin')->logout();

    $component->call('loadCurrentShift')->assertRedirect(route('kiosk.home'));
});

it('redirects to the staff table grid instead of crashing when the staff_pin session dies mid-poll on a trusted phone', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['trusted_device_user_id' => $waiter->id]);

    $component = Livewire::test('pos', ['table_id' => $table->id]);

    Auth::guard('staff_pin')->logout();

    $component->call('loadCurrentShift')->assertRedirect(route('staff.home'));
});

it('does not redirect admin panel usage, which never sets a kiosk or trusted-device session key', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $this->actingAs($waiter);

    Livewire::test('pos', ['table_id' => $table->id])
        ->call('loadCurrentShift')
        ->assertNoRedirect();
});
