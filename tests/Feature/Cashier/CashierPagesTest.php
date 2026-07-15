<?php

use App\Filament\Pages\CashierSessionPage;
use App\Filament\Pages\SettlementDetail;
use App\Filament\Pages\SupervisorDashboard;
use App\Filament\Pages\TransferQueue;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashierSettlementService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 7/8 of the cashier module: the actual Filament pages, driven
 * through their real Livewire components — including the one invariant
 * that matters most: the settlement page's rendered HTML must never
 * contain the staff-declared cash figure before the cashier's own count
 * has been committed.
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
});

function actingCashier3(): User
{
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    return $cashier;
}

function actingSupervisor(): User
{
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));

    return $supervisor;
}

it('verifies a transfer through the real transfer queue page', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $order = Order::create(['order_number' => 'ORD-PG-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 1000, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier3();

    $component = Livewire::actingAs($cashier)->test(TransferQueue::class);
    $component->callTableAction('verify', $payment);

    expect($payment->fresh()->verified)->toBeTrue();
});

it('flags a transfer through the real transfer queue page with a required reason', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $order = Order::create(['order_number' => 'ORD-PGF-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 2000, 'amount_paid' => 2000]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 2000, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier3();

    $component = Livewire::actingAs($cashier)->test(TransferQueue::class);
    $component->callTableAction('flag', $payment, data: ['reason_code' => 'not_found', 'note' => 'no matching alert']);

    expect($payment->fresh()->flagged)->toBeTrue();
    expect($payment->fresh()->flag_reason)->toBe('not_found');
});

it('never exposes the staff-declared cash figure on the settlement page before the cashier confirms', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(2), 'ended_at' => now(), 'status' => 'awaiting_cashier', 'declared_cash' => 12345.67]);
    $cashier = actingCashier3();

    $response = test()->actingAs($cashier)->get('/admin/settlement?shift=' . $shift->id);

    $response->assertOk();
    // The exact declared figure must not appear anywhere in the rendered
    // page before her count is committed.
    $response->assertDontSee('12,345.67');
});

it('reveals the staff-declared figure only after the cashier confirms cash', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(2), 'ended_at' => now(), 'status' => 'awaiting_cashier', 'declared_cash' => 8888.00]);
    $cashier = actingCashier3();

    // Drive the confirm through the real service (matching what the
    // page's confirmCash() action does internally) rather than fighting
    // Livewire::test()'s mount(Request) signature — the page's own
    // mount() reads the query string from the real HTTP request, which
    // Livewire's component-test harness doesn't simulate.
    (new CashierSettlementService())->confirmCash($shift, 8000, $cashier->id);

    expect((float) $shift->fresh()->cashier_counted_cash)->toBe(8000.0);

    $response = test()->actingAs($cashier)->get('/admin/settlement?shift=' . $shift->id);
    $response->assertSee('8,888.00'); // now visible, post-commit
});

it('opens a cashier session automatically on visiting the session page', function () {
    $cashier = actingCashier3();

    $response = test()->actingAs($cashier)->get('/admin/cashier-session-page');

    $response->assertOk();
    expect(\App\Models\CashierSession::where('user_id', $cashier->id)->where('status', 'open')->exists())->toBeTrue();
});

it('logs an outflow and declares close through the real session page', function () {
    $cashier = actingCashier3();

    $component = Livewire::actingAs($cashier)->test(CashierSessionPage::class);
    $component->set('outflowAmount', 500);
    $component->set('outflowType', 'deposit');
    $component->set('outflowNote', 'afternoon deposit');
    $component->call('logOutflow');

    $session = $component->instance()->session();
    expect($session->outflows()->count())->toBe(1);

    $component->set('declaredClosingCash', 1000);
    $component->call('declareClose');

    expect($session->fresh()->status)->toBe('pending_supervisor');
});

it('lets a supervisor rule a flagged transfer through the real dashboard page', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(1), 'ended_at' => now(), 'status' => 'awaiting_cashier']);
    $order = Order::create(['order_number' => 'ORD-SUP-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 700, 'amount_paid' => 700]);
    $payment = OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id, 'amount' => 700, 'method' => 'transfer', 'paid_at' => now()]);
    $cashier = actingCashier3();
    (new \App\Services\OrderPaymentVerificationService())->flag($payment, 'nothing matching', 'not_found', $cashier->id);

    $supervisor = actingSupervisor();
    $component = Livewire::actingAs($supervisor)->test(SupervisorDashboard::class);
    $component->call('openTransferRuling', $payment->id);
    $component->set('rulingNote', 'found it in the batch export');
    $component->call('ruleTransfer', 'late_verify');

    expect($payment->fresh()->ruling)->toBe('late_verify');
    expect($payment->fresh()->verified)->toBeTrue();
});

it('lets a supervisor blind-confirm a cashier session close-out through the real dashboard page', function () {
    $cashier = actingCashier3();
    $session = (new \App\Services\CashierSessionService())->currentOrOpen($cashier);
    // Nothing accrued (no settlements/drops confirmed) — expected is 0,
    // so declaring/confirming 3000 should surface as a 3000 gap, not a
    // clean close. A genuinely clean close is already covered directly
    // against the service in CashDropAndSessionTest.php; this test's job
    // is just proving the real dashboard page wires through correctly.
    (new \App\Services\CashierSessionService())->declareClose($session, 3000, $cashier->id);

    $supervisor = actingSupervisor();
    $component = Livewire::actingAs($supervisor)->test(SupervisorDashboard::class);
    $component->call('openSessionClose', $session->id);
    $component->set('supervisorCountedCash', 3000);
    $component->call('confirmSessionClose');

    expect($session->fresh()->status)->toBe('closed');
    expect((float) $session->fresh()->gap)->toBe(3000.0);
    expect(\App\Models\StaffDebt::where('user_id', $cashier->id)->where('reason', 'cashier_session_shortfall')->exists())->toBeFalse();
});

it('grants a cashier access to her pages and denies the supervisor-only dashboard', function () {
    $cashier = actingCashier3();

    test()->actingAs($cashier);
    expect(\App\Services\PermissionService::canAccessPage(TransferQueue::class))->toBeTrue();
    expect(\App\Services\PermissionService::canAccessPage(CashierSessionPage::class))->toBeTrue();
    expect(\App\Services\PermissionService::canAccessPage(SupervisorDashboard::class))->toBeFalse();
});

it('denies a plain waiter access to the cashier pages entirely', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    test()->actingAs($waiter);
    expect(\App\Services\PermissionService::canAccessPage(TransferQueue::class))->toBeFalse();
    expect(\App\Services\PermissionService::canAccessPage(SettlementDetail::class))->toBeFalse();
});
