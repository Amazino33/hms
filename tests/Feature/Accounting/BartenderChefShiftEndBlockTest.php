<?php

use App\Models\CountSession;
use App\Models\Shift;
use App\Models\User;
use App\Services\BartenderChefShiftService;
use App\Services\CountSessionService;
use App\Models\WareHouse;

/**
 * The generic topbar "End Shift" control (User::endShift(), used by every
 * logged-in user) previously had no idea a bartender/chef shift can only
 * legitimately end via a confirmed handover count — it would happily close
 * the shift directly, completely bypassing the requirement that "the
 * handover count IS the shift boundary." This locks that gap shut.
 */
it('refuses to end a bartender shift through the generic control, pointing to the handover flow instead', function () {
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    expect(fn () => $bartender->endShift())->toThrow(Exception::class);
    expect($bartender->currentShift()->status)->toBe('active');
});

it('refuses to end a chef shift through the generic control', function () {
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    expect(fn () => $chef->endShift())->toThrow(Exception::class);
    expect($chef->currentShift()->status)->toBe('active');
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
