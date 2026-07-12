<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\CountSession;
use App\Models\PagePermission;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Regression coverage for a real incident: a bartender accidentally named
 * himself as the incoming custodian on his own handover (or, more
 * generally, the same person got picked for two slots), leaving a session
 * permanently stuck — myOpenSession() kept finding it and blocking him
 * from ever reaching "Start Your Count" again.
 */
it('refuses to open a session where the incoming custodian is the same person as the outgoing', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();

    expect(fn () => (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, $bartender->id, $bartender->id))
        ->toThrow(Exception::class, 'The incoming custodian cannot be the same person as the outgoing custodian.');
});

it('refuses a witness who is the same person as the outgoing or incoming custodian', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    expect(fn () => (new CountSessionService())->openSession(
        'bar_handover', $bar->id, $incoming->id, outgoingUserId: $outgoing->id, incomingUserId: $incoming->id, witnessUserId: $outgoing->id,
    ))->toThrow(Exception::class, 'The witness cannot be the same person as the outgoing custodian.');

    expect(fn () => (new CountSessionService())->openSession(
        'bar_handover', $bar->id, $incoming->id, outgoingUserId: $outgoing->id, incomingUserId: $incoming->id, witnessUserId: $incoming->id,
    ))->toThrow(Exception::class, 'The witness cannot be the same person as the incoming custodian.');
});

it('lets the session owner cancel their own mistaken session before it is declared, freeing them to start a fresh one', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    // A solo opening count he now wants to clear (e.g. picked wrong, or
    // just wants to restart cleanly).
    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    Livewire::actingAs($bartender)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->assertSee('Cancel this session')
        ->call('cancelSession');

    expect($session->fresh()->status)->toBe('cancelled');
    expect($session->fresh()->cancelled_by)->toBe($bartender->id);
});

it('refuses to cancel a session that is not the callers and they are not a manager', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $stranger = User::factory()->create();
    $stranger->assignRole(Role::firstOrCreate(['name' => 'waiter']));
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'waiter'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'waiter']
    );

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    Livewire::actingAs($stranger)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('cancelSession');

    expect($session->fresh()->status)->toBe('counting'); // unchanged
});

it('lets a manager cancel any cancellable session from the admin Count Sessions list', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    (new CountSessionService())->cancelSession($session, $manager->id, 'Wrong person picked, restarting.');

    expect($session->fresh()->status)->toBe('cancelled');
    expect($session->fresh()->cancelled_reason)->toBe('Wrong person picked, restarting.');
});

it('refuses to cancel a session that has already moved past declared', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $manager = User::factory()->create();

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    (new CountSessionService())->confirmIncoming($session, $bartender->id);
    $session = (new CountSessionService())->submitForReview($session->fresh());

    expect(fn () => (new CountSessionService())->cancelSession($session, $manager->id))
        ->toThrow(Exception::class, 'This session has already moved past the point where it can be cancelled.');
});

it('stops blocking myOpenSession once the stuck session is cancelled, so the bartender can start fresh', function () {
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    expect(CountSession::where('id', $session->id)->exists())->toBeTrue();

    (new CountSessionService())->cancelSession($session, $bartender->id);

    $stillOpen = CountSession::where('type', 'bar_handover')
        ->whereIn('status', ['counting', 'declared', 'pending_review'])
        ->where(fn ($q) => $q->where('outgoing_user_id', $bartender->id)->orWhere('incoming_user_id', $bartender->id))
        ->exists();

    expect($stillOpen)->toBeFalse();
});
