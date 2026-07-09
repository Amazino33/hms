<?php

use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BartenderChefShiftService;
use App\Services\CountSessionService;

it('starts an opening shift from a reviewed solo opening count with no outgoing custodian', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $manager = User::factory()->create();

    $countService = new CountSessionService();
    $session = $countService->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $countService->confirmIncoming($session, $bartender->id);
    $session = $countService->submitForReview($session->fresh());
    $session = $countService->finalizeReview($session, $manager->id);

    $shift = (new BartenderChefShiftService())->startOpeningShift($bartender, 'bartender', $session);

    expect($shift->type)->toBe('bartender');
    expect($shift->opening_count_session_id)->toBe($session->id);
    expect($shift->isActive())->toBeTrue();
});

it('refuses an opening shift from a session that still has an outgoing custodian', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $countService = new CountSessionService();
    $session = $countService->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $countService->confirmOutgoing($session, $outgoing->id);
    $countService->confirmIncoming($session, $incoming->id);
    $session = $countService->submitForReview($session->fresh());
    // finalizeReview already starts the incoming shift automatically for a handover.
    $session = $countService->finalizeReview($session->fresh(), $manager->id);

    expect(fn () => (new BartenderChefShiftService())->startOpeningShift($incoming, 'bartender', $session))
        ->toThrow(Exception::class);
});

it('ends the outgoing custodian shift and starts the incoming one when a handover session is finalized', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    // Outgoing bartender already has an active shift, opened earlier today.
    $openingSessionForOutgoing = Shift::create([
        'user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(4), 'status' => 'active',
    ]);

    $countService = new CountSessionService();
    $session = $countService->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $countService->confirmOutgoing($session, $outgoing->id);
    $countService->confirmIncoming($session, $incoming->id);
    $session = $countService->submitForReview($session->fresh());
    $countService->finalizeReview($session->fresh(), $manager->id);

    expect($openingSessionForOutgoing->fresh()->isActive())->toBeFalse();

    $incomingShift = Shift::where('user_id', $incoming->id)->where('type', 'bartender')->first();
    expect($incomingShift)->not->toBeNull();
    expect($incomingShift->isActive())->toBeTrue();
    expect($incomingShift->opening_count_session_id)->toBe($session->id);
});

it('ends the outgoing custodian shift but starts no new one when a closing count is finalized', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $outgoing = User::factory()->create();
    $witness = User::factory()->create();
    $manager = User::factory()->create();

    $openingShift = Shift::create([
        'user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(4), 'status' => 'active',
    ]);

    $countService = new CountSessionService();
    $session = $countService->openSession(
        'bar_handover', $bar->id, $outgoing->id, $outgoing->id, $witness->id, isClosing: true,
    );
    $countService->confirmOutgoing($session, $outgoing->id);
    $countService->confirmIncoming($session, $witness->id);
    $session = $countService->submitForReview($session->fresh());
    $countService->finalizeReview($session->fresh(), $manager->id);

    expect($openingShift->fresh()->isActive())->toBeFalse();
    expect(Shift::where('user_id', $witness->id)->exists())->toBeFalse();
});

it('treats a shift left open past the stale threshold as not currently active', function () {
    $bartender = User::factory()->create();
    Shift::create([
        'user_id' => $bartender->id, 'type' => 'bartender',
        'started_at' => now()->subHours(Shift::STALE_AFTER_HOURS + 1), 'status' => 'active',
    ]);

    expect(Shift::query()->ofType('bartender')->activeNonStale('bartender')->exists())->toBeFalse();
});
