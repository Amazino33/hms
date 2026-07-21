<?php

use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;

/**
 * A waiter placing a bar/kitchen order (or anyone on the kiosk) had no way
 * to see who's actually on duty right now without navigating to a
 * different page entirely. The header badge reads directly off the same
 * active-shift query OrderSplitter itself checks, so "who's on duty" here
 * always matches who can actually take a bar/kitchen order right now.
 */
it('shows the on-duty bartender and chef by name in the POS header', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $bartender = User::factory()->create(['name' => 'Chidi']);
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $chef = User::factory()->create(['name' => 'Amaka']);
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->assertSee('Chidi')
        ->assertSee('Amaka');
});

it('shows "nobody" for a role with no active custodian, instead of silently omitting it', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $bartender = User::factory()->create(['name' => 'Chidi']);
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    // No chef shift at all.

    $component = Livewire::actingAs($waiter)->test('pos');

    $onDuty = $component->instance()->onDutyStaff();
    expect($onDuty['bartender'])->toBe('Chidi');
    expect($onDuty['chef'])->toBeNull();

    $component->assertSee('Chidi')->assertSee('nobody');
});

it('does not show a stale/ended shift as on duty', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $formerBartender = User::factory()->create(['name' => 'Tunde']);
    Shift::create([
        'user_id' => $formerBartender->id, 'type' => 'bartender',
        'started_at' => now()->subHours(3), 'ended_at' => now()->subHour(), 'status' => 'closed',
    ]);

    $onDuty = Livewire::actingAs($waiter)->test('pos')->instance()->onDutyStaff();

    expect($onDuty['bartender'])->toBeNull();
});
