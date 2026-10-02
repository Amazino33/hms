<?php

use App\Filament\Pages\TransferQueue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Services\CashierSettlementService;
use App\Services\FastMarkPaidService;
use App\Services\ShiftAccountingService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 0E — "Split by method" on fast Mark Paid. One action settles the
 * whole bill across up to three methods; nothing is deleted or re-created.
 * See docs/audits/split-by-method-verification.md for why settlement, the
 * cashier's blind confirmation and the daily snapshot are safe with it.
 */
function splitWaiter(): array
{
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    return [$waiter, $shift];
}

function splitTable(string $name = 'Split Table'): TableModel
{
    return TableModel::create(['name' => $name.' '.uniqid(), 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
}

function splitOrder(User $waiter, TableModel $table, float $total, string $createdAt, float $amountPaid = 0, string $destination = 'bar'): Order
{
    $order = Order::create([
        'order_number' => 'ORD-SPLIT-'.uniqid(),
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'served',
        'destination' => $destination,
        'total_amount' => $total,
        'amount_paid' => $amountPaid,
    ]);

    // Set explicitly so "oldest first" is decided by created_at, not by
    // whichever row happened to get the lower id.
    $order->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $order->fresh();
}

function paymentRows(Order $order): array
{
    return OrderPayment::where('order_id', $order->id)->orderBy('id')->get()
        ->map(fn (OrderPayment $p) => [$p->method, (float) $p->amount])
        ->all();
}

/** Two orders totalling ₦18,000 where the NEWER order has the lower id. */
function eighteenThousandTable(User $waiter): array
{
    $table = splitTable();
    $newer = splitOrder($waiter, $table, 11000, now()->subMinutes(5)->toDateTimeString());
    $older = splitOrder($waiter, $table, 7000, now()->subMinutes(20)->toDateTimeString(), destination: 'kitchen');

    return [$table, $older, $newer];
}

it('settles ₦18,000 as cash ₦10,000 + transfer ₦8,000, oldest order first, with both orders fully paid', function () {
    [$waiter] = splitWaiter();
    [$table, $older, $newer] = eighteenThousandTable($waiter);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->call('markPaidSplit', [
            ['method' => 'cash', 'amount' => 10000],
            ['method' => 'transfer', 'amount' => 8000, 'payer_reference' => 'Ada Obi'],
        ]);

    // Oldest (₦7,000) is filled first entirely from the first line (cash);
    // the newer ₦11,000 order takes the remaining ₦3,000 cash + ₦8,000 transfer.
    expect(paymentRows($older))->toBe([['cash', 7000.0]]);
    expect(paymentRows($newer))->toBe([['cash', 3000.0], ['transfer', 8000.0]]);

    expect((float) OrderPayment::whereIn('order_id', [$older->id, $newer->id])->sum('amount'))->toEqual(18000.0);

    foreach ([$older, $newer] as $order) {
        $order->refresh();
        expect($order->status)->toBe('paid');
        expect((float) $order->amount_paid)->toEqual((float) $order->total_amount);
    }

    expect($table->fresh()->status)->toBe('available');
});

it('settles correctly across three methods', function () {
    [$waiter] = splitWaiter();
    [$table, $older, $newer] = eighteenThousandTable($waiter);

    (new FastMarkPaidService)->payWithMethods(collect([$newer, $older]), [
        ['method' => 'cash', 'amount' => 5000],
        ['method' => 'transfer', 'amount' => 6000],
        ['method' => 'pos', 'amount' => 7000],
    ], $waiter);

    expect(paymentRows($older))->toBe([['cash', 5000.0], ['transfer', 2000.0]]);
    expect(paymentRows($newer))->toBe([['transfer', 4000.0], ['pos', 7000.0]]);
    expect($older->fresh()->status)->toBe('paid');
    expect($newer->fresh()->status)->toBe('paid');
});

it('rejects a short sum and an over sum server-side, writing nothing either time', function (array $lines, string $message) {
    [$waiter] = splitWaiter();
    [, $older, $newer] = eighteenThousandTable($waiter);

    expect(fn () => (new FastMarkPaidService)->payWithMethods(collect([$older, $newer]), $lines, $waiter))
        ->toThrow(Exception::class, $message);

    expect(OrderPayment::count())->toBe(0);
    expect($older->fresh()->status)->toBe('served');
    expect((float) $newer->fresh()->amount_paid)->toEqual(0.0);
})->with([
    'short' => [[['method' => 'cash', 'amount' => 10000], ['method' => 'transfer', 'amount' => 7999]], 'short'],
    'over' => [[['method' => 'cash', 'amount' => 10000], ['method' => 'transfer', 'amount' => 8000.01]], 'over'],
]);

it('rejects the same method twice, and more than three lines', function () {
    [$waiter] = splitWaiter();
    [, $older, $newer] = eighteenThousandTable($waiter);
    $service = new FastMarkPaidService;
    $orders = collect([$older, $newer]);

    expect(fn () => $service->payWithMethods($orders, [
        ['method' => 'cash', 'amount' => 9000],
        ['method' => 'cash', 'amount' => 9000],
    ], $waiter))->toThrow(Exception::class, 'only be used once');

    expect(fn () => $service->payWithMethods($orders, [
        ['method' => 'cash', 'amount' => 6000],
        ['method' => 'pos', 'amount' => 6000],
        ['method' => 'transfer', 'amount' => 3000],
        ['method' => 'cash', 'amount' => 3000],
    ], $waiter))->toThrow(Exception::class, 'between 1 and 3');

    expect(OrderPayment::count())->toBe(0);
});

it('rejects a payer reference on a cash line and saves it on a transfer line', function () {
    [$waiter] = splitWaiter();
    [, $older, $newer] = eighteenThousandTable($waiter);
    $service = new FastMarkPaidService;

    expect(fn () => $service->payWithMethods(collect([$older, $newer]), [
        ['method' => 'cash', 'amount' => 10000, 'payer_reference' => 'Ada Obi'],
        ['method' => 'transfer', 'amount' => 8000],
    ], $waiter))->toThrow(Exception::class, 'only go on a Transfer line');

    expect(fn () => $service->payWithMethods(collect([$older, $newer]), [
        ['method' => 'cash', 'amount' => 10000],
        ['method' => 'transfer', 'amount' => 8000, 'payer_reference' => str_repeat('x', 121)],
    ], $waiter))->toThrow(Exception::class, 'too long');

    expect(OrderPayment::count())->toBe(0);

    $service->payWithMethods(collect([$older, $newer]), [
        ['method' => 'cash', 'amount' => 10000],
        ['method' => 'transfer', 'amount' => 8000, 'payer_reference' => '  Ada Obi  '],
    ], $waiter);

    expect(OrderPayment::where('method', 'transfer')->pluck('payer_reference')->unique()->all())->toBe(['Ada Obi']);
    expect(OrderPayment::where('method', 'cash')->whereNotNull('payer_reference')->exists())->toBeFalse();
});

it('keeps single-method fast Mark Paid exactly as before: one row per order for its own outstanding balance', function () {
    [$waiter, $shift] = splitWaiter();
    $table = splitTable();
    $partlyPaid = splitOrder($waiter, $table, 1000, now()->subMinutes(10)->toDateTimeString(), amountPaid: 400);
    $unpaid = splitOrder($waiter, $table, 700, now()->subMinutes(30)->toDateTimeString(), destination: 'kitchen');

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->call('markPaidFast', 'pos')
        ->assertDispatched('order-completed');

    $rows = OrderPayment::orderBy('id')->get();
    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->method)->toBe('pos');
        expect($row->user_id)->toBe($waiter->id);
        expect($row->shift_id)->toBe($shift->id);
        expect($row->payer_reference)->toBeNull();
        expect($row->paid_at)->not->toBeNull();
    }

    expect(paymentRows($partlyPaid))->toBe([['pos', 600.0]]);
    expect(paymentRows($unpaid))->toBe([['pos', 700.0]]);
    expect($partlyPaid->fresh()->status)->toBe('paid');
    expect((float) $partlyPaid->fresh()->amount_paid)->toEqual(1000.0);
    expect($unpaid->fresh()->status)->toBe('paid');
    expect($table->fresh()->status)->toBe('available');
});

it('counts only the cash rows toward the waiter\'s expected cash at settlement', function () {
    [$waiter, $shift] = splitWaiter();
    [, $older, $newer] = eighteenThousandTable($waiter);

    (new FastMarkPaidService)->payWithMethods(collect([$older, $newer]), [
        ['method' => 'cash', 'amount' => 10000],
        ['method' => 'transfer', 'amount' => 8000],
    ], $waiter);

    $accounting = new ShiftAccountingService;

    expect($accounting->expectedCashRemittance($shift))->toEqual(10000.0);
    expect($accounting->expectedPosMachineTotal($shift))->toEqual(0.0);
    // Per destination too: the kitchen order took ₦7,000 cash, the bar order ₦3,000.
    expect($accounting->expectedCashForDestination($shift, 'kitchen'))->toEqual(7000.0);
    expect($accounting->expectedCashForDestination($shift, 'bar'))->toEqual(3000.0);
});

it('puts the transfer row, with its payer reference, in front of the cashier', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);

    [$waiter, $shift] = splitWaiter();
    [, $older, $newer] = eighteenThousandTable($waiter);

    (new FastMarkPaidService)->payWithMethods(collect([$older, $newer]), [
        ['method' => 'cash', 'amount' => 10000],
        ['method' => 'transfer', 'amount' => 8000, 'payer_reference' => 'Ada Obi'],
    ], $waiter);

    $transferRow = OrderPayment::where('method', 'transfer')->sole();
    expect($transferRow->shift_id)->toBe($shift->id);
    expect($transferRow->verified)->toBeFalse();

    // The settlement can't close until the cashier resolves it.
    expect((new CashierSettlementService)->transferChannelComplete($shift))->toBeFalse();

    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    Livewire::actingAs($cashier)
        ->test(TransferQueue::class)
        ->assertCanSeeTableRecords([$transferRow])
        ->assertSee('Ada Obi');
});

it('rejects a second submission on the same orders as already paid', function () {
    [$waiter] = splitWaiter();
    [$table, $older, $newer] = eighteenThousandTable($waiter);
    $service = new FastMarkPaidService;
    $lines = [['method' => 'cash', 'amount' => 10000], ['method' => 'transfer', 'amount' => 8000]];

    // The second device read the orders while they were still served.
    $staleCollection = collect([$older, $newer]);

    $service->payWithMethods($staleCollection, $lines, $waiter);

    expect(fn () => $service->payWithMethods($staleCollection, $lines, $waiter))
        ->toThrow(Exception::class, 'already been paid');

    // And from the till itself: nothing is left to pay at that table.
    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->call('markPaidSplit', $lines);

    expect(OrderPayment::count())->toBe(3);
    expect((float) OrderPayment::sum('amount'))->toEqual(18000.0);
});

it('computes the outstanding amount correctly when an order already had a partial payment', function () {
    [$waiter] = splitWaiter();
    $table = splitTable();
    $partlyPaid = splitOrder($waiter, $table, 1000, now()->subMinutes(30)->toDateTimeString(), amountPaid: 400);
    $unpaid = splitOrder($waiter, $table, 500, now()->subMinutes(10)->toDateTimeString());
    $service = new FastMarkPaidService;

    // ₦1,500 billed, ₦400 already in: ₦1,100 is what's owed, not ₦1,500.
    expect(fn () => $service->payWithMethods(collect([$partlyPaid, $unpaid]), [
        ['method' => 'cash', 'amount' => 1000],
        ['method' => 'transfer', 'amount' => 500],
    ], $waiter))->toThrow(Exception::class, 'over');

    $service->payWithMethods(collect([$partlyPaid, $unpaid]), [
        ['method' => 'cash', 'amount' => 600],
        ['method' => 'transfer', 'amount' => 500],
    ], $waiter);

    expect(paymentRows($partlyPaid))->toBe([['cash', 600.0]]);
    expect(paymentRows($unpaid))->toBe([['transfer', 500.0]]);
    expect((float) $partlyPaid->fresh()->amount_paid)->toEqual(1000.0);
});

it('leaves every order and order item id in place after payment, with no delete or re-create', function () {
    [$waiter] = splitWaiter();
    [$table, $older, $newer] = eighteenThousandTable($waiter);

    $item = OrderItem::create([
        'order_id' => $older->id, 'product_name' => 'Jollof Rice', 'item_type' => 'menu_item',
        'quantity' => 1, 'unit_price' => 7000, 'subtotal' => 7000,
    ]);

    $orderIdsBefore = Order::orderBy('id')->pluck('id')->all();

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->call('markPaidSplit', [
            ['method' => 'cash', 'amount' => 10000],
            ['method' => 'transfer', 'amount' => 8000],
        ]);

    expect(Order::orderBy('id')->pluck('id')->all())->toBe($orderIdsBefore);
    expect(OrderItem::find($item->id))->not->toBeNull();
    expect(OrderPayment::pluck('order_id')->unique()->sort()->values()->all())
        ->toBe(collect([$older->id, $newer->id])->sort()->values()->all());
});

it('shows the Split by method control under the fast Mark Paid buttons', function () {
    [$waiter] = splitWaiter();
    [$table] = eighteenThousandTable($waiter);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->assertSee('Split by method')
        ->assertSee('Payer name / reference');
});

it('reports the outstanding figure the split panel starts from', function () {
    [$waiter] = splitWaiter();
    $table = splitTable();
    splitOrder($waiter, $table, 1000, now()->subMinutes(5)->toDateTimeString(), amountPaid: 250);
    splitOrder($waiter, $table, 2000, now()->subMinutes(3)->toDateTimeString());

    $component = Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id);

    expect($component->instance()->fastPayOutstanding())->toEqual(2750.0);
});
