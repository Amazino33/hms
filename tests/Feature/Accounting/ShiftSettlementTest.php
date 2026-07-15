<?php

use App\Models\Order;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\CashierSettlementService;
use App\Services\ShiftAccountingService;
use Spatie\Permission\Models\Role;

/**
 * Settlement CLOSING (the cash/POS confirm -> variance -> debt part) moved
 * to CashierSettlementService with this build — the cashier confirms each
 * channel, not a supervisor in one step. $this->supervisor here now acts
 * purely as fallback (identical mechanics, same service), which is
 * exactly the scenario worth covering directly.
 */
beforeEach(function () {
    $this->service = new ShiftAccountingService();
    $this->settlement = new CashierSettlementService();
    $this->waiter = User::factory()->create();
    $this->supervisor = User::factory()->create();
    Role::firstOrCreate(['name' => 'manager']);
    $this->supervisor->assignRole('manager');
});

it('blocks ending a shift while an outstanding order exists', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'active']);
    Order::create([
        'order_number' => 'ORD-BLOCK-' . uniqid(),
        'shift_id' => $shift->id,
        'user_id' => $this->waiter->id,
        'status' => 'served',
        'total_amount' => 1000,
        'amount_paid' => 0,
    ]);

    expect(fn () => $this->waiter->endShift())->toThrow(\Exception::class);
    expect($shift->fresh()->status)->toBe('active');
});

it('allows ending a shift once the outstanding order is fully paid', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'active']);
    Order::create([
        'order_number' => 'ORD-PAID-' . uniqid(),
        'shift_id' => $shift->id,
        'user_id' => $this->waiter->id,
        'status' => 'paid',
        'total_amount' => 1000,
        'amount_paid' => 1000,
    ]);

    $ended = $this->waiter->endShift();

    expect($ended->status)->toBe('awaiting_cashier');
});

it('resolves an outstanding order and opens a debt when a supervisor converts it', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'active']);
    $order = Order::create([
        'order_number' => 'ORD-CONV-' . uniqid(),
        'shift_id' => $shift->id,
        'user_id' => $this->waiter->id,
        'status' => 'served',
        'total_amount' => 1500,
        'amount_paid' => 0,
    ]);

    $debt = $this->service->convertOrderToDebt($order, $this->supervisor, 'Guest left without paying');

    expect($debt->reason)->toBe('unpaid_order_conversion');
    expect((float) $debt->amount)->toBe(1500.0);
    expect($debt->order_id)->toBe($order->id);
    expect($order->fresh()->status)->toBe('paid');
    expect($this->service->outstandingOrders($shift))->toHaveCount(0);

    // The order is now resolved, so the shift can be ended.
    expect(fn () => $this->waiter->endShift())->not->toThrow(\Exception::class);
});

it('creates a shortfall debt when confirmed cash is below expected', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'awaiting_cashier']);
    $order = Order::create([
        'order_number' => 'ORD-SHORT-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $this->waiter->id,
        'status' => 'paid', 'total_amount' => 5000, 'amount_paid' => 5000, 'paid_cash' => 5000,
    ]);
    \App\Models\OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $shift->id,
        'amount' => 5000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    // Supervisor fallback — same mechanics a cashier would use.
    $this->settlement->confirmCash($shift, 4500.0, $this->supervisor->id);
    $confirmed = $this->settlement->confirmPos($shift->fresh(), 0, $this->supervisor->id);

    $debt = StaffDebt::where('shift_id', $shift->id)->first();
    expect($debt)->not->toBeNull();
    expect($debt->reason)->toBe('shift_shortfall');
    expect((float) $debt->amount)->toBe(500.0);

    expect($confirmed->status)->toBe('confirmed');
    expect((float) $confirmed->expected_cash)->toBe(5000.0);
    expect((float) $confirmed->cash_variance)->toBe(-500.0);
    expect((float) $confirmed->surplus_amount)->toBe(0.0);
});

it('refuses to confirm cash twice for the same settlement, closing the double-charge race', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'awaiting_cashier']);
    $order = Order::create([
        'order_number' => 'ORD-TWICE-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $this->waiter->id,
        'status' => 'paid', 'total_amount' => 5000, 'amount_paid' => 5000, 'paid_cash' => 5000,
    ]);
    \App\Models\OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $shift->id,
        'amount' => 5000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    $this->settlement->confirmCash($shift, 4500.0, $this->supervisor->id);

    // Simulates a double-click — the second call must be rejected, not
    // create a second confirmation for the same channel.
    expect(fn () => $this->settlement->confirmCash($shift->fresh(), 4500.0, $this->supervisor->id))
        ->toThrow(Exception::class);

    $this->settlement->confirmPos($shift->fresh(), 0, $this->supervisor->id);
    expect(StaffDebt::where('shift_id', $shift->id)->count())->toBe(1);
});

it('does not create a debt when confirmed cash matches expected exactly', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'awaiting_cashier']);
    $order = Order::create([
        'order_number' => 'ORD-EXACT-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $this->waiter->id,
        'status' => 'paid', 'total_amount' => 2000, 'amount_paid' => 2000, 'paid_cash' => 2000,
    ]);
    \App\Models\OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $shift->id,
        'amount' => 2000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    $this->settlement->confirmCash($shift, 2000.0, $this->supervisor->id);
    $confirmed = $this->settlement->confirmPos($shift->fresh(), 0, $this->supervisor->id);

    expect(StaffDebt::where('shift_id', $shift->id)->exists())->toBeFalse();
    expect($confirmed->status)->toBe('confirmed');
});

it('records a surplus without creating a debt when confirmed cash exceeds expected', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'awaiting_cashier']);
    $order = Order::create([
        'order_number' => 'ORD-SURPLUS-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $this->waiter->id,
        'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000, 'paid_cash' => 1000,
    ]);
    \App\Models\OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $shift->id,
        'amount' => 1000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    $this->settlement->confirmCash($shift, 1200.0, $this->supervisor->id);
    $confirmed = $this->settlement->confirmPos($shift->fresh(), 0, $this->supervisor->id);

    expect((float) $confirmed->cash_variance)->toBe(200.0);
    expect((float) $confirmed->surplus_amount)->toBe(200.0);
    expect(StaffDebt::where('shift_id', $shift->id)->exists())->toBeFalse();
});

it('notifies every supervisor-role user when a debt is created', function () {
    $anotherManager = User::factory()->create();
    $anotherManager->assignRole('manager');
    $irrelevantWaiter = User::factory()->create();

    StaffDebt::create([
        'user_id' => $this->waiter->id,
        'amount' => 25000, // above the 20000 "danger" threshold
        'reason' => 'manual',
        'status' => 'open',
        'created_by' => $this->supervisor->id,
    ]);

    expect($this->supervisor->fresh()->notifications()->count())->toBe(1);
    expect($anotherManager->fresh()->notifications()->count())->toBe(1);
    expect($irrelevantWaiter->fresh()->notifications()->count())->toBe(0);
});
