<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftAccountingService;

function makeShiftOrder(Shift $shift, array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ORD-ACC-' . uniqid(),
        'shift_id' => $shift->id,
        'user_id' => $shift->user_id,
        'status' => 'paid',
        'total_amount' => 1000,
        'amount_paid' => 1000,
        'paid_cash' => 1000,
        'paid_pos' => 0,
    ], $overrides));
}

beforeEach(function () {
    $this->service = new ShiftAccountingService();
    $this->waiter = User::factory()->create();
    $this->shift = Shift::create(['user_id' => $this->waiter->id, 'started_at' => now(), 'status' => 'active']);
});

it('counts a cash payment fully toward expected cash remittance', function () {
    $order = makeShiftOrder($this->shift);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $this->shift->id,
        'amount' => 1000, 'method' => 'cash', 'paid_at' => now(),
    ]);

    expect($this->service->expectedCashRemittance($this->shift))->toBe(1000.0);
    expect($this->service->expectedPosTotal($this->shift))->toBe(0.0);
});

it('counts a POS payment fully toward the POS total, not cash', function () {
    $order = makeShiftOrder($this->shift, ['paid_cash' => 0, 'paid_pos' => 1000]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $this->shift->id,
        'amount' => 1000, 'method' => 'pos', 'paid_at' => now(),
    ]);

    expect($this->service->expectedCashRemittance($this->shift))->toBe(0.0);
    expect($this->service->expectedPosTotal($this->shift))->toBe(1000.0);
});

it('splits a split-method payment using the order\'s own cash/pos snapshot', function () {
    $order = makeShiftOrder($this->shift, ['paid_cash' => 600, 'paid_pos' => 400]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $this->shift->id,
        'amount' => 1000, 'method' => 'split', 'paid_at' => now(),
    ]);

    expect($this->service->expectedCashRemittance($this->shift))->toBe(600.0);
    expect($this->service->expectedPosTotal($this->shift))->toBe(400.0);
});

it('only counts the actually-collected portion of a partial payment, and flags the remainder as outstanding', function () {
    $order = makeShiftOrder($this->shift, [
        'status' => 'partial', 'total_amount' => 1000, 'amount_paid' => 400,
        'paid_cash' => 400, 'paid_pos' => 0,
    ]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $this->shift->id,
        'amount' => 400, 'method' => 'cash', 'paid_at' => now(),
    ]);

    expect($this->service->expectedCashRemittance($this->shift))->toBe(400.0);
    expect($this->service->outstandingBalance($this->shift))->toBe(600.0);
    expect($this->service->outstandingOrders($this->shift))->toHaveCount(1);
});

it('excludes cancelled orders from remittance and from outstanding balance', function () {
    $cancelled = makeShiftOrder($this->shift, [
        'status' => 'cancelled', 'total_amount' => 1000, 'amount_paid' => 0, 'paid_cash' => 0,
    ]);
    OrderPayment::create([
        'order_id' => $cancelled->id, 'user_id' => $this->waiter->id, 'shift_id' => $this->shift->id,
        'amount' => 500, 'method' => 'cash', 'paid_at' => now(),
    ]);

    expect($this->service->expectedCashRemittance($this->shift))->toBe(0.0);
    expect($this->service->outstandingOrders($this->shift))->toHaveCount(0);
});

it('does not list a fully-paid order as outstanding', function () {
    makeShiftOrder($this->shift); // status paid, amount_paid == total_amount

    expect($this->service->outstandingOrders($this->shift))->toHaveCount(0);
    expect($this->service->outstandingBalance($this->shift))->toBe(0.0);
});

it('reflects a reduced total after an item-level return on an unpaid order', function () {
    // Simulates the Increment 1 fix: total_amount already recalculated
    // downward after a return, before any payment happened.
    $order = makeShiftOrder($this->shift, [
        'status' => 'served', 'total_amount' => 1500, 'amount_paid' => 0, 'paid_cash' => 0,
    ]);

    expect($this->service->outstandingBalance($this->shift))->toBe(1500.0);

    // Return shrinks the order's stored total (as the POS return handler now does).
    $order->update(['total_amount' => 1000]);

    expect($this->service->outstandingBalance($this->shift))->toBe(1000.0);
});
