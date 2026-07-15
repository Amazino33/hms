<?php

use App\Models\CashDrop;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\CashDropService;
use App\Services\CashierSessionService;
use App\Services\CashierSettlementService;
use Spatie\Permission\Models\Role;

/**
 * Step 3/6 of the cashier module: cash drops routed to a shared queue
 * (not a named recipient) and the cashier's own custody-chain session
 * (accrual, outflows, blind supervisor close-out) — deliberately never
 * gated on her own unclosed prior session.
 */
function actingCashier2(): User
{
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    return $cashier;
}

it('declares a cash drop with no named recipient, pending in the shared queue', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $drop = (new CashDropService())->declare($waiter, 5000, 'end of lunch rush');

    expect($drop->received_by)->toBeNull();
    expect($drop->status)->toBe('pending');
});

it('lets any eligible cashier confirm a drop, not a specifically named one', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $drop = (new CashDropService())->declare($waiter, 5000, null);

    $cashier = actingCashier2();
    $confirmed = (new CashDropService())->confirm($drop, $cashier);

    expect($confirmed->status)->toBe('confirmed');
    expect($confirmed->received_by)->toBe($cashier->id);
    expect((float) $confirmed->confirmed_amount)->toBe(5000.0);
});

it('records a different confirmed amount when the cashier counts something else', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $drop = (new CashDropService())->declare($waiter, 5000, null);

    $cashier = actingCashier2();
    $confirmed = (new CashDropService())->confirm($drop, $cashier, 4800);

    expect((float) $confirmed->confirmed_amount)->toBe(4800.0);
    expect((float) $confirmed->declared_amount)->toBe(5000.0); // untouched
});

it('rejects confirmation from a role not eligible to receive drops', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $drop = (new CashDropService())->declare($waiter, 5000, null);
    $otherWaiter = User::factory()->create();

    expect(fn () => (new CashDropService())->confirm($drop, $otherWaiter))->toThrow(Exception::class);
});

it('opens a cashier session on first use, then reuses the same open one', function () {
    $cashier = actingCashier2();
    $service = new CashierSessionService();

    $first = $service->currentOrOpen($cashier);
    $second = $service->currentOrOpen($cashier);

    expect($first->id)->toBe($second->id);
    expect($first->status)->toBe('open');
});

it('accrues confirmed settlement cash and confirmed drops into the session, live', function () {
    $cashier = actingCashier2();
    $session = (new CashierSessionService())->currentOrOpen($cashier);

    // A settlement she confirms.
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(3), 'ended_at' => now(), 'status' => 'awaiting_cashier']);
    \App\Models\Order::create(['order_number' => 'ORD-ACC-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 3000, 'amount_paid' => 3000, 'paid_cash' => 3000]);
    \App\Models\OrderPayment::create(['order_id' => \App\Models\Order::latest()->first()->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 3000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true]);
    (new CashierSettlementService())->confirmCash($shift, 3000, $cashier->id);
    (new CashierSettlementService())->confirmPos($shift->fresh(), 0, $cashier->id);

    // A drop she confirms.
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $drop = (new \App\Services\CashDropService())->declare($waiter, 1000, null);
    (new \App\Services\CashDropService())->confirm($drop, $cashier);

    expect((new CashierSessionService())->accruedCash($session->fresh()))->toBe(4000.0);
});

it('subtracts logged outflows from accrued cash', function () {
    $cashier = actingCashier2();
    $service = new CashierSessionService();
    $session = $service->currentOrOpen($cashier);

    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(2), 'ended_at' => now(), 'status' => 'awaiting_cashier']);
    \App\Models\Order::create(['order_number' => 'ORD-OUT-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 10000, 'amount_paid' => 10000]);
    \App\Models\OrderPayment::create(['order_id' => \App\Models\Order::latest()->first()->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 10000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true]);
    (new CashierSettlementService())->confirmCash($shift, 10000, $cashier->id);
    (new CashierSettlementService())->confirmPos($shift->fresh(), 0, $cashier->id);

    $service->logOutflow($session->fresh(), 6000, 'deposit', 'bank deposit at 3pm', $cashier->id);

    expect($service->accruedCash($session->fresh()))->toBe(4000.0);
});

it('freezes the accrual window at declare time, ignoring anything confirmed after', function () {
    $cashier = actingCashier2();
    $service = new CashierSessionService();
    $session = $service->currentOrOpen($cashier);

    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(1), 'ended_at' => now(), 'status' => 'awaiting_cashier']);
    \App\Models\Order::create(['order_number' => 'ORD-FRZ-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 2000, 'amount_paid' => 2000]);
    \App\Models\OrderPayment::create(['order_id' => \App\Models\Order::latest()->first()->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 2000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true]);
    (new CashierSettlementService())->confirmCash($shift, 2000, $cashier->id);
    (new CashierSettlementService())->confirmPos($shift->fresh(), 0, $cashier->id);

    // SQLite (the test DB) doesn't honor microsecond timestamp precision
    // the way MySQL does, so two actions this close together can collide
    // on the same stored second — advance the clock explicitly rather
    // than relying on real elapsed wall-clock time.
    \Illuminate\Support\Carbon::setTestNow(now()->addSecond());
    $service->declareClose($session->fresh(), 2000, $cashier->id);
    \Illuminate\Support\Carbon::setTestNow(now()->addSecond());

    // Something confirmed AFTER she declared close must not count.
    $shift2 = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'ended_at' => now(), 'status' => 'awaiting_cashier']);
    \App\Models\Order::create(['order_number' => 'ORD-LATE-' . uniqid(), 'shift_id' => $shift2->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 500, 'amount_paid' => 500]);
    \App\Models\OrderPayment::create(['order_id' => \App\Models\Order::latest()->first()->id, 'user_id' => $waiter->id, 'shift_id' => $shift2->id, 'amount' => 500, 'method' => 'cash', 'paid_at' => now(), 'verified' => true]);
    (new CashierSettlementService())->confirmCash($shift2, 500, $cashier->id);
    (new CashierSettlementService())->confirmPos($shift2->fresh(), 0, $cashier->id);

    expect($service->accruedCash($session->fresh()))->toBe(2000.0);

    \Illuminate\Support\Carbon::setTestNow();
});

it('closes clean with no debt when the supervisor count matches accrued exactly', function () {
    $cashier = actingCashier2();
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));
    $service = new CashierSessionService();
    $session = $service->currentOrOpen($cashier);

    $service->declareClose($session, 0, $cashier->id);
    $closed = $service->confirmCloseBySupervisor($session->fresh(), 0, $supervisor->id);

    expect($closed->status)->toBe('closed');
    expect((float) $closed->gap)->toBe(0.0);
    expect(StaffDebt::where('user_id', $cashier->id)->count())->toBe(0);
});

it('creates a cashier_session_shortfall StaffDebt when the supervisor counts less than expected', function () {
    $cashier = actingCashier2();
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));
    $service = new CashierSessionService();
    $session = $service->currentOrOpen($cashier);

    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(1), 'ended_at' => now(), 'status' => 'awaiting_cashier']);
    \App\Models\Order::create(['order_number' => 'ORD-GAP-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 5000, 'amount_paid' => 5000]);
    \App\Models\OrderPayment::create(['order_id' => \App\Models\Order::latest()->first()->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 5000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true]);
    (new CashierSettlementService())->confirmCash($shift, 5000, $cashier->id);
    (new CashierSettlementService())->confirmPos($shift->fresh(), 0, $cashier->id);

    // SQLite (the test DB) doesn't honor microsecond timestamp precision
    // the way MySQL does, so two actions this close together can collide
    // on the same stored second — advance the clock explicitly rather
    // than relying on real elapsed wall-clock time.
    \Illuminate\Support\Carbon::setTestNow(now()->addSecond());
    $service->declareClose($session->fresh(), 5000, $cashier->id);
    $closed = $service->confirmCloseBySupervisor($session->fresh(), 4700, $supervisor->id);
    \Illuminate\Support\Carbon::setTestNow();

    expect((float) $closed->gap)->toBe(-300.0);
    $debt = StaffDebt::where('user_id', $cashier->id)->first();
    expect($debt)->not->toBeNull();
    expect($debt->reason)->toBe('cashier_session_shortfall');
    expect((float) $debt->amount)->toBe(300.0);
});

it('never gates the cashier on her own unclosed or gap-carrying prior session', function () {
    $cashier = actingCashier2();
    $service = new CashierSessionService();

    $stale = $service->currentOrOpen($cashier);
    $service->declareClose($stale, 1000, $cashier->id); // now pending_supervisor, never closed

    // She keeps working — a brand new session opens for her without any
    // block or exception, unlike the staff shift-start gate.
    $fresh = $service->currentOrOpen($cashier);

    expect($fresh->id)->not->toBe($stale->id);
    expect($fresh->status)->toBe('open');
});
