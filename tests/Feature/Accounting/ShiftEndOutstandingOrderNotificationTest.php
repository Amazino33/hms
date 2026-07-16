<?php

use App\Livewire\ShiftManager;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;

/**
 * Part of the system-wide notification/silent-failure fix: pins that the
 * outstanding-orders guard in User::endShift() actually reaches the user as
 * a persistent danger notification through the real page component (not
 * just that the model throws), and that a blocked attempt never mutates the
 * shift.
 */
it('blocks ending a waiter shift with an unpaid order, sending a persistent danger notification and leaving the shift untouched', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $order = Order::create([
        'order_number' => 'ORD-SHEND-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'partial', 'total_amount' => 1000, 'amount_paid' => 400,
        'paid_cash' => 400, 'paid_pos' => 0,
    ]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
        'amount' => 400, 'method' => 'cash', 'paid_at' => now(),
    ]);

    session()->forget('filament.notifications');

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('confirmShiftEnd', 400, 0);

    $last = collect(session('filament.notifications', []))->last();

    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('danger');
    expect($last['duration'])->toBe('persistent');
    expect($last['title'])->toContain('unpaid order');

    expect($shift->fresh()->status)->toBe('active');
    expect($shift->fresh()->ended_at)->toBeNull();
});

it('ends a waiter shift with a success notification once nothing is outstanding', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $order = Order::create([
        'order_number' => 'ORD-SHEND-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000,
        'paid_cash' => 1000, 'paid_pos' => 0,
    ]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
        'amount' => 1000, 'method' => 'cash', 'paid_at' => now(),
    ]);

    session()->forget('filament.notifications');

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('confirmShiftEnd', 1000, 0);

    $last = collect(session('filament.notifications', []))->last();

    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('success');
    expect($last['title'])->toBe('Shift Ended Successfully');

    expect($shift->fresh()->status)->toBe('awaiting_cashier');
    expect($shift->fresh()->ended_at)->not->toBeNull();
});
