<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\ShiftChannelConfirmation;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\CashierSettlementService;
use App\Services\SettlementFlagRulingService;
use Spatie\Permission\Models\Role;

/**
 * A waiter shift that served BOTH bar and kitchen orders confirms cash/POS
 * separately per destination instead of one combined figure — everyone
 * else (receptionist always, or a waiter who only served one destination)
 * keeps using the original single-figure confirmCash()/confirmPos() flow
 * completely unchanged (covered already by ShiftSettlementTest.php and
 * SettlementConfirmationTest.php).
 */
beforeEach(function () {
    $this->settlement = new CashierSettlementService;
    $this->waiter = User::factory()->create();
    $this->supervisor = User::factory()->create();
    Role::firstOrCreate(['name' => 'manager']);
    $this->supervisor->assignRole('manager');
});

function seedMixedDestinationShift(User $waiter, float $barCash = 3000, float $kitchenCash = 2000): Shift
{
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    $barOrder = Order::create([
        'order_number' => 'ORD-BAR-'.uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id, 'destination' => 'bar',
        'status' => 'paid', 'total_amount' => $barCash, 'amount_paid' => $barCash, 'paid_cash' => $barCash,
    ]);
    OrderPayment::create([
        'order_id' => $barOrder->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
        'amount' => $barCash, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    $kitchenOrder = Order::create([
        'order_number' => 'ORD-KITCHEN-'.uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id, 'destination' => 'kitchen',
        'status' => 'paid', 'total_amount' => $kitchenCash, 'amount_paid' => $kitchenCash, 'paid_cash' => $kitchenCash,
    ]);
    OrderPayment::create([
        'order_id' => $kitchenOrder->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
        'amount' => $kitchenCash, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    return $shift->fresh();
}

it('does not use the channel split for a waiter who only served one destination', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);
    $order = Order::create([
        'order_number' => 'ORD-SOLO-'.uniqid(),
        'shift_id' => $shift->id, 'user_id' => $this->waiter->id, 'destination' => 'bar',
        'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000, 'paid_cash' => 1000,
    ]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $this->waiter->id, 'shift_id' => $shift->id,
        'amount' => 1000, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    expect($this->settlement->usesChannelSplit($shift))->toBeFalse();
});

it('uses the channel split for a waiter who served both bar and kitchen', function () {
    $shift = seedMixedDestinationShift($this->waiter);

    expect($this->settlement->usesChannelSplit($shift))->toBeTrue();
    expect($this->settlement->activeDestinations($shift))->toEqualCanonicalizing(['bar', 'kitchen']);
});

it('does not finalize until all four destination/channel confirmations are done', function () {
    $shift = seedMixedDestinationShift($this->waiter);

    $this->settlement->confirmChannelForDestination($shift, 'bar', 'cash', 3000, $this->supervisor->id);
    expect($shift->fresh()->status)->toBe('awaiting_cashier');

    $this->settlement->confirmChannelForDestination($shift->fresh(), 'bar', 'pos', 0, $this->supervisor->id);
    expect($shift->fresh()->status)->toBe('awaiting_cashier');

    $this->settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'cash', 2000, $this->supervisor->id);
    expect($shift->fresh()->status)->toBe('awaiting_cashier');

    $confirmed = $this->settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'pos', 0, $this->supervisor->id);
    expect($confirmed->status)->toBe('confirmed');
});

it('aggregates confirmed amounts across destinations onto the shift, computing a shortfall correctly', function () {
    $shift = seedMixedDestinationShift($this->waiter, barCash: 3000, kitchenCash: 2000);

    $this->settlement->confirmChannelForDestination($shift, 'bar', 'cash', 2800, $this->supervisor->id); // 200 short
    $this->settlement->confirmChannelForDestination($shift->fresh(), 'bar', 'pos', 0, $this->supervisor->id);
    $this->settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'cash', 2000, $this->supervisor->id);
    $confirmed = $this->settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'pos', 0, $this->supervisor->id);

    expect($confirmed->status)->toBe('confirmed');
    expect((float) $confirmed->cashier_counted_cash)->toBe(4800.0); // 2800 + 2000
    expect((float) $confirmed->expected_cash)->toBe(5000.0); // 3000 + 2000
    expect((float) $confirmed->cash_variance)->toBe(-200.0);

    $debt = StaffDebt::where('shift_id', $shift->id)->first();
    expect($debt)->not->toBeNull();
    expect((float) $debt->amount)->toBe(200.0);
    expect($debt->reason)->toBe('shift_shortfall');
});

it('refuses to confirm the same destination/channel twice', function () {
    $shift = seedMixedDestinationShift($this->waiter);

    $this->settlement->confirmChannelForDestination($shift, 'bar', 'cash', 3000, $this->supervisor->id);

    expect(fn () => $this->settlement->confirmChannelForDestination($shift->fresh(), 'bar', 'cash', 3000, $this->supervisor->id))
        ->toThrow(Exception::class);
});

it('flags a destination-specific POS mismatch and blocks finalization until ruled', function () {
    $shift = seedMixedDestinationShift($this->waiter);

    $this->settlement->confirmChannelForDestination($shift, 'bar', 'cash', 3000, $this->supervisor->id);
    $this->settlement->confirmChannelForDestination($shift->fresh(), 'bar', 'pos', 500, $this->supervisor->id); // expected 0, mismatch
    $this->settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'cash', 2000, $this->supervisor->id);
    $this->settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'pos', 0, $this->supervisor->id);

    expect($shift->fresh()->status)->toBe('awaiting_cashier');
    expect($shift->fresh()->hasOpenFlag())->toBeTrue();

    $flaggedRow = ShiftChannelConfirmation::where('shift_id', $shift->id)->where('destination', 'bar')->where('channel', 'pos')->first();
    expect($flaggedRow->flagged)->toBeTrue();

    $ruled = (new SettlementFlagRulingService)->ruleChannelConfirmation($flaggedRow, 'charge', 'Genuine bar POS shortfall', $this->supervisor->id);
    expect($ruled->flagged)->toBeFalse();
    expect($ruled->ruling)->toBe('charge');

    $confirmed = $shift->fresh();
    expect($confirmed->status)->toBe('confirmed');

    $debt = StaffDebt::where('shift_id', $shift->id)->first();
    expect($debt)->not->toBeNull();
    expect((float) $debt->amount)->toBe(500.0); // the charged POS mismatch, no cash shortfall
});

it('refuses to rule a channel confirmation that is not flagged', function () {
    $shift = seedMixedDestinationShift($this->waiter);
    $this->settlement->confirmChannelForDestination($shift, 'bar', 'cash', 3000, $this->supervisor->id);
    $row = ShiftChannelConfirmation::where('shift_id', $shift->id)->where('destination', 'bar')->where('channel', 'cash')->first();

    expect(fn () => (new SettlementFlagRulingService)->ruleChannelConfirmation($row, 'charge', 'note', $this->supervisor->id))
        ->toThrow(Exception::class);
});

it('leaves the receptionist single-figure flow completely untouched by usesChannelSplit', function () {
    $shift = Shift::create(['user_id' => $this->waiter->id, 'type' => 'receptionist', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    expect($this->settlement->usesChannelSplit($shift))->toBeFalse();
});
