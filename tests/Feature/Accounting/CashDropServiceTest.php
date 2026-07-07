<?php

use App\Models\CashDrop;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashDropService;
use App\Services\ShiftAccountingService;
use Spatie\Permission\Models\Role;

it('lets a waiter on an active shift declare a cash drop to a named manager', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $drop = (new CashDropService())->declare($waiter, $manager, 10000, 'Midday drop');

    expect($drop->status)->toBe('pending');
    expect((float) $drop->declared_amount)->toEqual(10000.0);
    expect($drop->waiter_id)->toBe($waiter->id);
    expect($drop->received_by)->toBe($manager->id);
    expect($drop->shift_id)->toBe($shift->id);
});

it('refuses to declare a drop without an active shift', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect(fn () => (new CashDropService())->declare($waiter, $manager, 5000))->toThrow(Exception::class);
});

it('refuses to declare a drop to someone who is not a manager/admin/super_admin', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $otherWaiter = User::factory()->create();
    $otherWaiter->assignRole('waiter');

    expect(fn () => (new CashDropService())->declare($waiter, $otherWaiter, 5000))->toThrow(Exception::class);
});

it('refuses to let a manager-role waiter declare a cash drop to themselves', function () {
    Role::firstOrCreate(['name' => 'manager']);
    // A realistic small-restaurant setup: someone holds the manager role
    // but also works a waiter shift and takes tables directly.
    $managerWaiter = User::factory()->create();
    $managerWaiter->assignRole('manager');
    Shift::create(['user_id' => $managerWaiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    expect(fn () => (new CashDropService())->declare($managerWaiter, $managerWaiter, 10000))
        ->toThrow(Exception::class);
});

it('refuses to let a receiver confirm a drop after being demoted from a receiver-eligible role', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $drop = (new CashDropService())->declare($waiter, $manager, 10000);

    $manager->removeRole('manager');

    expect(fn () => (new CashDropService())->confirm($drop, $manager))->toThrow(Exception::class);
});

it('refuses a zero or negative declared amount', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    expect(fn () => (new CashDropService())->declare($waiter, $manager, 0))->toThrow(Exception::class);
});

it('lets the exact named receiver confirm the declared amount', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $service = new CashDropService();
    $drop = $service->declare($waiter, $manager, 10000);
    $confirmed = $service->confirm($drop, $manager);

    expect($confirmed->status)->toBe('confirmed');
    expect((float) $confirmed->confirmed_amount)->toEqual(10000.0);
    expect($confirmed->confirmed_at)->not->toBeNull();
});

it('lets the named receiver correct the amount to what they actually counted', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $service = new CashDropService();
    $drop = $service->declare($waiter, $manager, 10000);
    $confirmed = $service->confirm($drop, $manager, 9500);

    expect((float) $confirmed->declared_amount)->toEqual(10000.0);
    expect((float) $confirmed->confirmed_amount)->toEqual(9500.0);
});

it('refuses to let a different manager confirm a drop that was not addressed to them', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $otherManager = User::factory()->create();
    $otherManager->assignRole('manager');

    $drop = (new CashDropService())->declare($waiter, $manager, 10000);

    expect(fn () => (new CashDropService())->confirm($drop, $otherManager))->toThrow(Exception::class);
});

it('refuses to confirm an already-confirmed drop', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $service = new CashDropService();
    $drop = $service->declare($waiter, $manager, 10000);
    $service->confirm($drop, $manager);

    expect(fn () => $service->confirm($drop->fresh(), $manager))->toThrow(Exception::class);
});

it('reduces expected cash remittance only by confirmed drops, never pending ones', function () {
    Role::firstOrCreate(['name' => 'manager']);
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $order = Order::create(['order_number' => 'ORD-' . uniqid(), 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'status' => 'paid', 'destination' => 'bar', 'total_amount' => 20000, 'amount_paid' => 20000]);
    OrderPayment::create(['order_id' => $order->id, 'amount' => 20000, 'method' => 'cash', 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'paid_at' => now()]);

    $accounting = new ShiftAccountingService();
    expect($accounting->expectedCashRemittance($shift))->toEqual(20000.0);

    $service = new CashDropService();
    $pendingDrop = $service->declare($waiter, $manager, 8000);

    // Still pending — no reduction yet.
    expect($accounting->expectedCashRemittance($shift))->toEqual(20000.0);

    $service->confirm($pendingDrop, $manager);

    // Now confirmed — reduces what's still owed.
    expect($accounting->expectedCashRemittance($shift))->toEqual(12000.0);
});
