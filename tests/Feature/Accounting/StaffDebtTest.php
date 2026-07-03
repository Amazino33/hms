<?php

use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;

it('starts a debt as open and needs no repayment to stay that way', function () {
    $waiter = User::factory()->create();
    $supervisor = User::factory()->create();

    $debt = StaffDebt::create([
        'user_id' => $waiter->id,
        'amount' => 5000,
        'reason' => 'shift_shortfall',
        'status' => 'open',
        'created_by' => $supervisor->id,
    ]);

    expect($debt->status)->toBe('open');
    expect($debt->remainingBalance())->toBe(5000.0);
});

it('moves to partially_settled after a partial repayment', function () {
    $waiter = User::factory()->create();
    $supervisor = User::factory()->create();

    $debt = StaffDebt::create([
        'user_id' => $waiter->id,
        'amount' => 5000,
        'reason' => 'shift_shortfall',
        'status' => 'open',
        'created_by' => $supervisor->id,
    ]);

    $debt->repayments()->create([
        'amount' => 2000,
        'method' => 'cash',
        'recorded_by' => $supervisor->id,
    ]);
    $debt->refreshStatus();

    expect($debt->fresh()->status)->toBe('partially_settled');
    expect($debt->remainingBalance())->toBe(3000.0);
});

it('moves to settled once repayments cover the full amount, via mixed methods', function () {
    $waiter = User::factory()->create();
    $supervisor = User::factory()->create();

    $debt = StaffDebt::create([
        'user_id' => $waiter->id,
        'amount' => 5000,
        'reason' => 'shift_shortfall',
        'status' => 'open',
        'created_by' => $supervisor->id,
    ]);

    $debt->repayments()->create(['amount' => 2000, 'method' => 'cash', 'recorded_by' => $supervisor->id]);
    $debt->repayments()->create(['amount' => 1500, 'method' => 'commission_offset', 'recorded_by' => $supervisor->id]);
    $debt->repayments()->create(['amount' => 1500, 'method' => 'salary_deduction', 'recorded_by' => $supervisor->id]);
    $debt->refreshStatus();

    expect($debt->fresh()->status)->toBe('settled');
    expect($debt->remainingBalance())->toBe(0.0);
});

it('links a debt to the shift and waiter it arose from', function () {
    $waiter = User::factory()->create();
    $supervisor = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'pending_supervisor']);

    $debt = StaffDebt::create([
        'user_id' => $waiter->id,
        'shift_id' => $shift->id,
        'amount' => 1200,
        'reason' => 'shift_shortfall',
        'status' => 'open',
        'created_by' => $supervisor->id,
    ]);

    expect($debt->shift->id)->toBe($shift->id);
    expect($waiter->debts()->first()->id)->toBe($debt->id);
    expect($shift->debts()->first()->id)->toBe($debt->id);
});
