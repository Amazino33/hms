<?php

use App\Models\CashDrop;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashDropService;
use App\Services\ShiftAccountingService;
use Spatie\Permission\Models\Role;

/**
 * Rewritten for the cashier module: a drop no longer names a specific
 * recipient at declare time — it routes to a shared queue any eligible
 * role (cashier, manager, admin, super_admin) can confirm. Whoever
 * actually confirms becomes the recorded receiver at that point, not
 * before.
 */
it('declares a cash drop with an active shift, no named recipient required', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $drop = (new CashDropService())->declare($waiter, 10000, 'Midday drop');

    expect($drop->status)->toBe('pending');
    expect((float) $drop->declared_amount)->toEqual(10000.0);
    expect($drop->waiter_id)->toBe($waiter->id);
    expect($drop->received_by)->toBeNull();
    expect($drop->shift_id)->toBe($shift->id);
});

it('refuses to declare a drop without an active shift', function () {
    $waiter = User::factory()->create();

    expect(fn () => (new CashDropService())->declare($waiter, 5000))->toThrow(Exception::class);
});

it('refuses a zero or negative declared amount', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    expect(fn () => (new CashDropService())->declare($waiter, 0))->toThrow(Exception::class);
});

it('lets any eligible role confirm a pending drop from the shared queue', function () {
    Role::firstOrCreate(['name' => 'cashier']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $service = new CashDropService();
    $drop = $service->declare($waiter, 10000);
    $confirmed = $service->confirm($drop, $cashier);

    expect($confirmed->status)->toBe('confirmed');
    expect($confirmed->received_by)->toBe($cashier->id);
    expect((float) $confirmed->confirmed_amount)->toEqual(10000.0);
    expect($confirmed->confirmed_at)->not->toBeNull();
});

it('lets a manager confirm a drop too, as fallback', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $service = new CashDropService();
    $drop = $service->declare($waiter, 10000);
    $confirmed = $service->confirm($drop, $manager);

    expect($confirmed->status)->toBe('confirmed');
    expect($confirmed->received_by)->toBe($manager->id);
});

it('refuses confirmation from a role not eligible to receive drops', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $otherWaiter = User::factory()->create();
    $otherWaiter->assignRole('waiter');

    $drop = (new CashDropService())->declare($waiter, 5000);

    expect(fn () => (new CashDropService())->confirm($drop, $otherWaiter))->toThrow(Exception::class);
});

it('lets the confirming user correct the amount to what they actually counted', function () {
    Role::firstOrCreate(['name' => 'cashier']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $service = new CashDropService();
    $drop = $service->declare($waiter, 10000);
    $confirmed = $service->confirm($drop, $cashier, 9500);

    expect((float) $confirmed->declared_amount)->toEqual(10000.0);
    expect((float) $confirmed->confirmed_amount)->toEqual(9500.0);
});

it('refuses to confirm an already-confirmed drop', function () {
    Role::firstOrCreate(['name' => 'cashier']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $service = new CashDropService();
    $drop = $service->declare($waiter, 10000);
    $service->confirm($drop, $cashier);

    expect(fn () => $service->confirm($drop->fresh(), $cashier))->toThrow(Exception::class);
});

it('reduces expected cash remittance only by confirmed drops, never pending ones', function () {
    Role::firstOrCreate(['name' => 'cashier']);
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $order = Order::create(['order_number' => 'ORD-' . uniqid(), 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'status' => 'paid', 'destination' => 'bar', 'total_amount' => 20000, 'amount_paid' => 20000]);
    OrderPayment::create(['order_id' => $order->id, 'amount' => 20000, 'method' => 'cash', 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'paid_at' => now()]);

    $accounting = new ShiftAccountingService();
    expect($accounting->expectedCashRemittance($shift))->toEqual(20000.0);

    $service = new CashDropService();
    $pendingDrop = $service->declare($waiter, 8000);

    // Still pending — no reduction yet.
    expect($accounting->expectedCashRemittance($shift))->toEqual(20000.0);

    $service->confirm($pendingDrop, $cashier);

    // Now confirmed — reduces what's still owed.
    expect($accounting->expectedCashRemittance($shift))->toEqual(12000.0);
});
