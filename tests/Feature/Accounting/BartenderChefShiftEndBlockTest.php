<?php

use App\Models\CountSession;
use App\Models\Shift;
use App\Models\User;
use App\Services\BartenderChefShiftService;
use App\Services\CountSessionService;
use App\Models\WareHouse;

/**
 * The counting-based handover flow (My Handover Count) is currently
 * disabled — bartenders/chefs end their shift through the same generic
 * topbar control as every other role, no count required. This can be
 * re-tightened later by reinstating the block this file used to test.
 */
it('allows a bartender to end their shift through the generic control while counting is disabled', function () {
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $ended = $bartender->endShift();

    expect($ended->status)->toBe('pending_supervisor');
});

it('allows a chef to end their shift through the generic control while counting is disabled', function () {
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    $ended = $chef->endShift();

    expect($ended->status)->toBe('pending_supervisor');
});

it('still allows a waiter to end their shift through the generic control, unaffected by the bartender/chef block', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $ended = $waiter->endShift();

    expect($ended->status)->toBe('pending_supervisor');
});

it('still ends a bartender shift correctly through the dedicated handover-confirmation flow', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();
    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $session = $session->fresh();
    $session->update(['status' => 'reviewed', 'reviewed_by' => $manager->id, 'reviewed_at' => now()]);

    (new BartenderChefShiftService())->applyHandoverShiftBoundary($session);

    expect($outgoing->fresh()->currentShift())->toBeNull();
    expect(Shift::where('user_id', $incoming->id)->where('type', 'bartender')->active()->exists())->toBeTrue();
});
