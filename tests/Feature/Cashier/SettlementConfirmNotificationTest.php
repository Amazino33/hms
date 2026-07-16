<?php

use App\Filament\Pages\SettlementDetail;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Part of the system-wide notification/silent-failure fix: pins that
 * CashierSettlementService's "already confirmed" guard reaches the user as
 * a persistent danger notification without re-mutating the settlement, and
 * that a genuine shortfall confirmation (which does NOT block — it records
 * a StaffDebt) sends success notifications for both channels.
 */
function seedSettlementScenario(): array
{
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    $shift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter',
        'started_at' => now()->subHours(3), 'ended_at' => now(), 'status' => 'awaiting_cashier',
    ]);

    $order = Order::create([
        'order_number' => 'ORD-SETL-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000,
        'paid_cash' => 1000, 'paid_pos' => 0,
    ]);
    OrderPayment::create([
        'order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
        'amount' => 1000, 'method' => 'cash', 'paid_at' => now(),
    ]);

    return compact('waiter', 'cashier', 'shift');
}

/**
 * Filament Page components with mount(Request $request) don't mount
 * cleanly through Livewire::test()'s snapshot protocol outside a real
 * panel request (matching the established pattern in
 * tests/Feature/Pos/ServedWorkflowTest.php), so the page's action methods
 * are driven directly here instead.
 */
function loadSettlementDetailPage(User $actingAs, int $shiftId): SettlementDetail
{
    auth()->login($actingAs);

    $page = new SettlementDetail();
    $page->mount(Request::create('/admin/settlement', 'GET', ['shift' => $shiftId]));

    return $page;
}

it('blocks confirming cash a second time, sending a persistent danger notification and leaving the first confirmation untouched', function () {
    ['cashier' => $cashier, 'shift' => $shift] = seedSettlementScenario();

    $page = loadSettlementDetailPage($cashier, $shift->id);
    $page->cashierCountedCash = 1000;
    $page->confirmCash();

    expect((float) $shift->fresh()->cashier_counted_cash)->toBe(1000.0);

    session()->forget('filament.notifications');

    $page = loadSettlementDetailPage($cashier, $shift->id);
    $page->cashierCountedCash = 999;
    $page->confirmCash();

    $last = collect(session('filament.notifications', []))->last();

    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('danger');
    expect($last['duration'])->toBe('persistent');
    expect($last['title'])->toBe('Could not confirm cash');
    expect($last['body'])->toContain('already been confirmed');

    // Still the first committed figure, not overwritten by the blocked attempt.
    expect((float) $shift->fresh()->cashier_counted_cash)->toBe(1000.0);
});

it('confirms a cash shortfall with success notifications on both channels, and records the resulting StaffDebt', function () {
    ['cashier' => $cashier, 'shift' => $shift, 'waiter' => $waiter] = seedSettlementScenario();

    session()->forget('filament.notifications');

    $page = loadSettlementDetailPage($cashier, $shift->id);
    $page->cashierCountedCash = 800; // expected 1000 -> 200 shortfall
    $page->confirmCash();

    $afterCash = collect(session('filament.notifications', []))->last();
    expect($afterCash['status'])->toBe('success');
    expect($afterCash['title'])->toBe('Cash confirmed');

    $page = loadSettlementDetailPage($cashier, $shift->id);
    $page->posMachineAmount = 0; // expected POS is also 0 -> matches, no flag
    $page->confirmPos();

    $afterPos = collect(session('filament.notifications', []))->last();
    expect($afterPos['status'])->toBe('success');
    expect($afterPos['title'])->toBe('POS total confirmed');

    expect($shift->fresh()->status)->toBe('confirmed');

    $debt = StaffDebt::where('user_id', $waiter->id)->where('shift_id', $shift->id)->first();
    expect($debt)->not->toBeNull();
    expect($debt->reason)->toBe('shift_shortfall');
    expect((float) $debt->amount)->toBe(200.0);
});
