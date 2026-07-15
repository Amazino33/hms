<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\CashierSettlementService;
use App\Services\OrderPaymentVerificationService;
use App\Services\SettingsService;
use App\Services\SettlementFlagRulingService;
use Spatie\Permission\Models\Role;

/**
 * Step 1/2/4/5 of the cashier module: the shift-start gate, the fixed
 * force-close bug, and the new channel-by-channel settlement confirmation
 * (replacing the old single-step supervisor confirm entirely — nothing
 * closes a settlement except CashierSettlementService now).
 */
function seedAwaitingCashierShift(float $cashAmount = 5000, string $type = 'waiter'): array
{
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => $type, 'started_at' => now()->subHours(4), 'ended_at' => now(), 'status' => 'awaiting_cashier', 'declared_cash' => $cashAmount, 'declared_pos' => 0]);
    $order = Order::create([
        'order_number' => 'ORD-CSHR-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'paid', 'total_amount' => $cashAmount, 'amount_paid' => $cashAmount, 'paid_cash' => $cashAmount,
    ]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
        'amount' => $cashAmount, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
    ]);

    return [$waiter, $shift];
}

function actingCashier(): User
{
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    return $cashier;
}

// ── Shift-start gate ────────────────────────────────────────────────────

it('does not force-close a still-active shift when starting again — returns it unchanged', function () {
    $waiter = User::factory()->create();
    $shift = $waiter->startShift();

    $again = $waiter->startShift();

    expect($again->id)->toBe($shift->id);
    expect($shift->fresh()->status)->toBe('active');
});

it('blocks starting a new shift while a prior settlement is awaiting cashier confirmation', function () {
    [$waiter] = seedAwaitingCashierShift();

    expect(fn () => $waiter->startShift())->toThrow(Exception::class);
});

it('allows starting a new shift with a warning when the setting admits it', function () {
    [$waiter] = seedAwaitingCashierShift();
    $admin = User::factory()->create();
    SettingsService::setBool('allow_shift_start_with_unsettled', true, $admin->id);

    $newShift = $waiter->startShift();

    expect($newShift->status)->toBe('active');
    expect(Shift::hasUnsettledFor($waiter->id))->toBeTrue(); // still visible for the UI warning banner
});

it('logs a setting change via activity', function () {
    $admin = User::factory()->create();
    SettingsService::setBool('allow_shift_start_with_unsettled', true, $admin->id);

    $activity = \Spatie\Activitylog\Models\Activity::where('log_name', 'setting')->latest()->first();
    expect($activity)->not->toBeNull();
    expect($activity->causer_id)->toBe($admin->id);
});

// ── Channel confirmation ────────────────────────────────────────────────

it('confirms a settlement once cash and POS are confirmed and there are no transfers', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(5000);
    $cashier = actingCashier();
    $service = new CashierSettlementService();

    $service->confirmCash($shift, 5000, $cashier->id);
    $confirmed = $service->confirmPos($shift->fresh(), 0, $cashier->id);

    expect($confirmed->status)->toBe('confirmed');
    expect((float) $confirmed->cash_variance)->toBe(0.0);
    expect(StaffDebt::where('shift_id', $shift->id)->count())->toBe(0);
});

it('blind: creates a debt from cashier-counted cash, not the staff-declared figure', function () {
    // Staff declared 5000, but only 4000 was actually collected in cash —
    // the debt must reflect the cashier's count, not the declaration.
    [$waiter, $shift] = seedAwaitingCashierShift(5000);
    $cashier = actingCashier();
    $service = new CashierSettlementService();

    $service->confirmCash($shift, 4000, $cashier->id);
    $confirmed = $service->confirmPos($shift->fresh(), 0, $cashier->id);

    expect($confirmed->status)->toBe('confirmed');
    $debt = StaffDebt::where('shift_id', $shift->id)->first();
    expect($debt)->not->toBeNull();
    expect((float) $debt->amount)->toBe(1000.0);
    expect($debt->reason)->toBe('shift_shortfall');
    // The staff's own declaration is untouched, still there for audit —
    // just never consulted by the debt math.
    expect((float) $shift->fresh()->declared_cash)->toBe(5000.0);
});

it('does not confirm until both cash and POS channels are done', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(5000);
    $cashier = actingCashier();
    $service = new CashierSettlementService();

    $afterCashOnly = $service->confirmCash($shift, 5000, $cashier->id);
    expect($afterCashOnly->status)->toBe('awaiting_cashier');
});

it('refuses to confirm cash twice for the same settlement', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(5000);
    $cashier = actingCashier();
    $service = new CashierSettlementService();

    $service->confirmCash($shift, 5000, $cashier->id);

    expect(fn () => $service->confirmCash($shift->fresh(), 5000, $cashier->id))->toThrow(Exception::class);
});

it('a POS-machine mismatch flags instead of auto-creating debt, blocking confirmation', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    OrderPayment::create([
        'order_id' => Order::create(['order_number' => 'ORD-POS-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 3000, 'amount_paid' => 3000])->id,
        'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 3000, 'method' => 'pos', 'paid_at' => now(), 'verified' => true,
    ]);
    $cashier = actingCashier();
    $service = new CashierSettlementService();

    $service->confirmCash($shift, 0, $cashier->id);
    $afterMismatch = $service->confirmPos($shift->fresh(), 2500, $cashier->id); // machine says 2500, expected 3000

    expect($afterMismatch->status)->toBe('awaiting_cashier'); // blocked, not confirmed
    expect($afterMismatch->pos_flagged)->toBeTrue();
    expect(StaffDebt::where('shift_id', $shift->id)->count())->toBe(0); // no auto-debt
});

it('a flagged transfer blocks confirmation until ruled', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    $order = Order::create(['order_number' => 'ORD-TR-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 2000, 'amount_paid' => 2000]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 2000, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier();

    (new OrderPaymentVerificationService())->flag($payment, 'not seen in the bank app', 'not_found', $cashier->id);

    $service = new CashierSettlementService();
    $service->confirmCash($shift, 0, $cashier->id);
    $afterPos = $service->confirmPos($shift->fresh(), 0, $cashier->id);

    expect($afterPos->status)->toBe('awaiting_cashier');
    expect($afterPos->hasOpenFlag())->toBeTrue();
});

it('verifying a transfer auto-completes the channel and can complete the settlement', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    $order = Order::create(['order_number' => 'ORD-TRV-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1500, 'amount_paid' => 1500]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 1500, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier();
    $service = new CashierSettlementService();

    $service->confirmCash($shift, 0, $cashier->id);
    $service->confirmPos($shift->fresh(), 0, $cashier->id);

    expect($shift->fresh()->status)->toBe('awaiting_cashier'); // transfer still unverified

    (new OrderPaymentVerificationService())->verify($payment, $cashier->id);

    expect($shift->fresh()->status)->toBe('confirmed'); // verifying it finished the job
});

it('rules a flagged transfer: late_verify counts as verified', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    $order = Order::create(['order_number' => 'ORD-LV-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 800, 'amount_paid' => 800]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 800, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier();
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));

    (new OrderPaymentVerificationService())->flag($payment, 'could not find it at first', 'not_found', $cashier->id);
    $ruled = (new SettlementFlagRulingService())->ruleTransfer($payment->fresh(), 'late_verify', 'found it in yesterday\'s batch', $supervisor->id);

    expect($ruled->verified)->toBeTrue();
    expect($ruled->ruling)->toBe('late_verify');
});

it('rules a flagged transfer: charge converts the amount into settlement shortfall at confirmation', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    $order = Order::create(['order_number' => 'ORD-CHG-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1200, 'amount_paid' => 1200]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 1200, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier();
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));

    (new OrderPaymentVerificationService())->flag($payment, 'never received', 'not_found', $cashier->id);

    $service = new CashierSettlementService();
    $service->confirmCash($shift, 0, $cashier->id);
    $service->confirmPos($shift->fresh(), 0, $cashier->id);

    expect($shift->fresh()->status)->toBe('awaiting_cashier');

    (new SettlementFlagRulingService())->ruleTransfer($payment->fresh(), 'charge', 'guest never actually paid', $supervisor->id);

    $finalShift = $shift->fresh();
    expect($finalShift->status)->toBe('confirmed');
    $debt = StaffDebt::where('shift_id', $shift->id)->first();
    expect($debt)->not->toBeNull();
    expect((float) $debt->amount)->toBe(1200.0);
});

it('rules a flagged transfer: void clears it without creating debt', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    $order = Order::create(['order_number' => 'ORD-VOID-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 600, 'amount_paid' => 600]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 600, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier();
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));

    (new OrderPaymentVerificationService())->flag($payment, 'duplicate record', 'duplicate', $cashier->id);

    $service = new CashierSettlementService();
    $service->confirmCash($shift, 0, $cashier->id);
    $service->confirmPos($shift->fresh(), 0, $cashier->id);

    (new SettlementFlagRulingService())->ruleTransfer($payment->fresh(), 'void', 'confirmed duplicate, ignore', $supervisor->id);

    $finalShift = $shift->fresh();
    expect($finalShift->status)->toBe('confirmed');
    expect(StaffDebt::where('shift_id', $shift->id)->count())->toBe(0);
});

it('rules a POS-machine dispute: charge adds the mismatch to the settlement debt', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    OrderPayment::create([
        'order_id' => Order::create(['order_number' => 'ORD-POSC-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 4000, 'amount_paid' => 4000])->id,
        'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 4000, 'method' => 'pos', 'paid_at' => now(), 'verified' => true,
    ]);
    $cashier = actingCashier();
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));
    $service = new CashierSettlementService();

    $service->confirmCash($shift, 0, $cashier->id);
    $service->confirmPos($shift->fresh(), 3500, $cashier->id); // machine short by 500

    (new SettlementFlagRulingService())->rulePosMachine($shift->fresh(), 'charge', 'machine batch confirmed short', $supervisor->id);

    $finalShift = $shift->fresh();
    expect($finalShift->status)->toBe('confirmed');
    $debt = StaffDebt::where('shift_id', $shift->id)->first();
    expect((float) $debt->amount)->toBe(500.0);
});

it('refuses to rule a flag that is not open', function () {
    [$waiter, $shift] = seedAwaitingCashierShift(0);
    $order = Order::create(['order_number' => 'ORD-NF-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 300, 'amount_paid' => 300]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 300, 'method' => 'transfer', 'paid_at' => now()]);
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));

    expect(fn () => (new SettlementFlagRulingService())->ruleTransfer($payment, 'void', 'n/a', $supervisor->id))->toThrow(Exception::class);
});

// ── Receptionist parity ─────────────────────────────────────────────────

it('confirms a receptionist settlement the same way, expected cash including the starting float', function () {
    $receptionist = User::factory()->create();
    $shift = Shift::create(['user_id' => $receptionist->id, 'type' => 'receptionist', 'starting_float' => 5000, 'started_at' => now()->subHours(3), 'ended_at' => now(), 'status' => 'awaiting_cashier', 'declared_cash' => 8000, 'declared_pos' => 0]);
    $guest = \App\Models\Guest::create(['name' => 'Cashier Test Guest', 'phone' => '0820' . fake()->numerify('#######')]);
    $room = \App\Models\Room::create(['number' => 'CSH-1', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $booking = \App\Models\Booking::create(['room_id' => $room->id, 'guest_id' => $guest->id, 'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'total_price' => 15000, 'status' => 'checked_in']);
    $folio = \App\Models\Folio::create(['booking_id' => $booking->id]);
    \App\Models\FolioLine::create(['folio_id' => $folio->id, 'type' => 'payment', 'amount' => -3000, 'description' => 'Cash payment', 'created_by' => $receptionist->id, 'shift_id' => $shift->id, 'payment_method' => 'cash', 'verified' => true]);

    $cashier = actingCashier();
    $service = new CashierSettlementService();

    // Expected cash = 5000 float + 3000 collected = 8000.
    expect($service->expectedCash($shift))->toBe(8000.0);

    $service->confirmCash($shift, 8000, $cashier->id);
    $confirmed = $service->confirmPos($shift->fresh(), 0, $cashier->id);

    expect($confirmed->status)->toBe('confirmed');
    expect(StaffDebt::where('shift_id', $shift->id)->count())->toBe(0);
});
