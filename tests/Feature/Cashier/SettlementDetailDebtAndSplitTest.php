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
 * Filament Page components with mount(Request $request) don't mount
 * cleanly through Livewire::test()'s snapshot protocol outside a real
 * panel request (matching the established pattern in
 * SettlementConfirmNotificationTest.php), so the page's action methods
 * are driven directly here instead.
 */
function loadSettlementPage(User $actingAs, int $shiftId): SettlementDetail
{
    auth()->login($actingAs);

    $page = new SettlementDetail;
    $page->mount(Request::create('/admin/settlement', 'GET', ['shift' => $shiftId]));

    return $page;
}

it('records a manual debt against the shift\'s staff member from the settlement page', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    $page = loadSettlementPage($cashier, $shift->id);
    $page->debtAmount = 1500;
    $page->debtNotes = 'Missing change given to a customer';
    $page->recordDebt();

    $debt = StaffDebt::where('shift_id', $shift->id)->first();
    expect($debt)->not->toBeNull();
    expect($debt->user_id)->toBe($waiter->id);
    expect((float) $debt->amount)->toBe(1500.0);
    expect($debt->reason)->toBe('manual');
    expect($debt->created_by)->toBe($cashier->id);
});

it('refuses to record a debt with a zero or missing amount', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    $page = loadSettlementPage($cashier, $shift->id);
    $page->debtAmount = null;
    $page->recordDebt();

    expect(StaffDebt::where('shift_id', $shift->id)->count())->toBe(0);
});

it('lists this staff member\'s other open debts, not settled ones', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    StaffDebt::create(['user_id' => $waiter->id, 'amount' => 1000, 'reason' => 'manual', 'status' => 'open', 'created_by' => $cashier->id]);
    StaffDebt::create(['user_id' => $waiter->id, 'amount' => 2000, 'reason' => 'manual', 'status' => 'settled', 'created_by' => $cashier->id]);

    $page = loadSettlementPage($cashier, $shift->id);

    expect($page->staffDebts())->toHaveCount(1);
    expect((float) $page->staffDebts()->first()->amount)->toBe(1000.0);
});

it('shows bar/kitchen split confirmation panels for a mixed-destination waiter shift', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    foreach (['bar' => 3000, 'kitchen' => 2000] as $destination => $amount) {
        $order = Order::create([
            'order_number' => 'ORD-'.strtoupper($destination).'-'.uniqid(),
            'shift_id' => $shift->id, 'user_id' => $waiter->id, 'destination' => $destination,
            'status' => 'paid', 'total_amount' => $amount, 'amount_paid' => $amount, 'paid_cash' => $amount,
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
            'amount' => $amount, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
        ]);
    }

    $page = loadSettlementPage($cashier, $shift->id);

    expect($page->usesChannelSplit())->toBeTrue();
    expect($page->activeDestinations())->toEqualCanonicalizing(['bar', 'kitchen']);
    expect($page->expectedForDestination('bar', 'cash'))->toBe(3000.0);
    expect($page->expectedForDestination('kitchen', 'cash'))->toBe(2000.0);
});

it('confirms bar cash independently through the settlement page', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    foreach (['bar' => 3000, 'kitchen' => 2000] as $destination => $amount) {
        $order = Order::create([
            'order_number' => 'ORD-'.strtoupper($destination).'-'.uniqid(),
            'shift_id' => $shift->id, 'user_id' => $waiter->id, 'destination' => $destination,
            'status' => 'paid', 'total_amount' => $amount, 'amount_paid' => $amount, 'paid_cash' => $amount,
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
            'amount' => $amount, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
        ]);
    }

    $page = loadSettlementPage($cashier, $shift->id);
    $page->barCashAmount = 3000;
    $page->confirmChannel('bar', 'cash');

    $confirmation = $shift->fresh()->channelConfirmations()->where('destination', 'bar')->where('channel', 'cash')->first();
    expect($confirmation)->not->toBeNull();
    expect((float) $confirmation->confirmed_amount)->toBe(3000.0);
});
