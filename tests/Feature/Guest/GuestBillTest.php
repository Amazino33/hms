<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\GuestPaymentClaim;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTableSession;
use App\Models\GuestWaiterCall;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\TableMove;
use App\Models\TransferAccount;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BrandingLogo;
use App\Services\FastMarkPaidService;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestClaimService;
use App\Services\Guest\GuestRequestException;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\GuestRoundService;
use App\Services\Guest\GuestTablePaymentService;
use App\Services\Guest\GuestWaiterCallService;
use App\Services\Guest\SameGuestsQuestion;
use App\Services\Guest\TableCloseService;
use App\Services\Guest\TableMoveService;
use App\Services\PinAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 4 — live bill, claims, split pre-fill, another round, call waiter,
 * move and close table.
 */
function gbUser(string $name, ?string $role = null, ?string $pin = null): User
{
    $user = User::factory()->create(['name' => $name]);

    if ($role) {
        $user->assignRole(Role::firstOrCreate(['name' => $role]));
    }

    if ($pin) {
        (new PinAuthService)->setPin($user, $pin);
    }

    return $user;
}

function gbFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Beers', 'type' => 'drink']);
    $food = Category::create(['name' => 'Rice', 'type' => 'food']);
    DB::table('category_chip_group')->insert([
        ['category_id' => $drinks->id, 'chip_group_id' => DB::table('chip_groups')->where('name', 'Temperature')->value('id')],
    ]);

    $beer = Product::create(['name' => 'Star Beer', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 40]);
    $malt = Product::create(['name' => 'Maltina', 'price' => 800, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $malt->id, 'warehouse_id' => $bar->id, 'quantity' => 40]);
    $jollof = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-GB-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]);

    $emeka = gbUser('Emeka Obi', 'waiter', '4826');
    $emekaShift = Shift::create(['user_id' => $emeka->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $tunde = gbUser('Tunde Bello', 'waiter', '5937');
    Shift::create(['user_id' => $tunde->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $manager = gbUser('Grace Manager', 'manager', '6148');
    $bartender = gbUser('Bisi Bar', 'bartender');
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $t5 = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $t9 = TableModel::create(['name' => 'Table 9', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $t3 = TableModel::create(['name' => 'Table 3', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    Cache::flush();

    return compact('beer', 'malt', 'jollof', 'emeka', 'emekaShift', 'tunde', 'manager', 'bartender', 't5', 't9', 't3');
}

function gbDevice(string $c = 'a'): string
{
    return str_repeat($c, 32);
}

function gbSubmit(array $f, string $device = 'a', ?array $lines = null, ?TableModel $table = null): GuestRequest
{
    return (new GuestRequestService)->submit(($table ?? $f['t5'])->qr_token, gbDevice($device), $lines ?? [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold']],
    ]);
}

/** A plain POS order straight onto a table. */
function gbOrder(TableModel $table, string $status, array $items, ?User $user = null, $createdAt = null): Order
{
    $total = collect($items)->sum(fn ($i) => $i[1] * $i[2]);
    $order = Order::create([
        'order_number' => 'ORD-GB-'.uniqid(),
        'table_id' => $table->id,
        'user_id' => $user?->id,
        'status' => $status,
        'destination' => 'bar',
        'total_amount' => $total,
        'amount_paid' => in_array($status, ['paid'], true) ? $total : 0,
    ]);

    foreach ($items as [$name, $qty, $price]) {
        OrderItem::create(['order_id' => $order->id, 'product_name' => $name, 'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $qty * $price]);
    }

    if ($createdAt) {
        $order->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $order->fresh();
}

function gbBill($test, array $f, string $device = 'a', ?TableModel $table = null)
{
    return $test->withCredentials()->withUnencryptedCookie('selum_gd', gbDevice($device))
        ->getJson('/m/'.($table ?? $f['t5'])->qr_token.'/bill');
}

/** A sitting with an accepted request and an unpaid, served ₦18,000 bill. */
function gbServedBill(array $f, int $total = 18000): GuestTableSession
{
    $request = gbSubmit($f, 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]]);
    (new GuestRequestService)->confirm($request, $f['emeka']);
    $session = GuestTableSession::sole();
    gbOrder($f['t5'], 'served', [['Hennessy VS', 1, $total]], $f['emeka']);

    return $session->fresh();
}

it('builds the bill from the D19 orders, greyed waiting lines and removed lines — never other tables or older orders', function () {
    $f = gbFixture();
    $old = gbOrder($f['t5'], 'served', [['Old Gin', 1, 7000]], null, now()->subHours(2));
    $this->travel(1)->minutes();

    $request = gbSubmit($f, 'a', [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold']],
        ['type' => 'product', 'id' => $f['malt']->id, 'qty' => 1],
    ]);
    (new GuestRequestService)->confirm($request, $f['emeka'], false);
    $malt = $request->items()->where('item_id', $f['malt']->id)->sole();
    (new GuestBarReleaseService)->remove($malt, 'Out of stock');

    $this->travel(1)->minutes();
    $mine = gbOrder($f['t5'], 'served', [['Chapman', 2, 2500], ['Water', 1, 500]], $f['emeka']);
    $mine->items()->where('product_name', 'Water')->update(['quantity' => 0, 'subtotal' => 0]); // voided
    $mine->update(['total_amount' => 5000]); // as UnreturnableVoidService recomputes it
    gbOrder($f['t9'], 'served', [['Other Table Wine', 1, 9000]]);

    $bill = gbBill($this, $f)->assertOk()->json('bill');

    expect(collect($bill['sections']['on_bill'])->pluck('name')->all())->toBe(['Chapman']);
    expect($bill['sections']['on_bill'][0])->toMatchArray(['qty' => 2, 'price' => 2500, 'total' => 5000]);
    expect(collect($bill['sections']['waiting'])->pluck('name')->all())->toBe(['Star Beer']);
    expect($bill['sections']['waiting'][0]['chips'])->toBe(['Cold']);
    expect($bill['sections']['waiting'][0]['status_label'])->toBe('At the bar');
    $unavailable = collect($bill['sections']['unavailable'])->pluck('reason', 'name')->all();
    expect($unavailable)->toBe(['Water' => 'Removed by staff', 'Maltina' => 'Out of stock']);
    expect($bill['totals']['bill'])->toBe(5000);

    $json = json_encode($bill);
    expect(str_contains($json, 'Old Gin'))->toBeFalse();
    expect(str_contains($json, 'Other Table Wine'))->toBeFalse();
    expect($old->fresh()->status)->toBe('served');
});

it('asks "Same guests?" on the first acceptance when older unpaid orders exist (D19)', function (bool $same) {
    $f = gbFixture();
    $old = gbOrder($f['t5'], 'served', [['Old Gin', 1, 7000]], null, now()->subHour());
    $request = gbSubmit($f);

    // No answer: refused, nothing changes.
    expect(fn () => (new GuestRequestService)->confirm($request, $f['emeka']))
        ->toThrow(SameGuestsQuestion::class, 'Table 5 already has ₦7,000 unpaid. Same guests?');
    expect($request->fresh()->status)->toBe('pending');

    (new GuestRequestService)->confirm($request, $f['emeka'], $same);
    $session = GuestTableSession::sole();
    $names = collect(gbBill($this, $f)->json('bill.sections.on_bill'))->pluck('name')->all();

    if ($same) {
        expect($session->bill_from_at->equalTo($old->created_at))->toBeTrue();
        expect($names)->toContain('Old Gin');
    } else {
        expect($session->bill_from_at->equalTo($session->opened_at))->toBeTrue();
        expect($names)->not->toContain('Old Gin');
    }

    // Asked once only: a later request is accepted without the question.
    $later = gbSubmit($f, 'b');
    expect((new GuestRequestService)->confirm($later, $f['emeka'])->status)->toBe('confirmed');
})->with(['yes' => true, 'no' => false]);

it('does not ask "Same guests?" when the older orders owe nothing (₦0)', function () {
    $f = gbFixture();
    $paid = gbOrder($f['t5'], 'served', [['Old Gin', 1, 7000]], null, now()->subHour());
    $paid->update(['amount_paid' => 7000]);
    gbOrder($f['t5'], 'served', [['Comp Water', 1, 0]], null, now()->subHour());
    $request = gbSubmit($f);

    expect(GuestRequestService::earlierUnpaid($request->session))->toBeNull();
    expect((new GuestRequestService)->confirm($request, $f['emeka'])->status)->toBe('confirmed');
});

it('computes Remaining as unpaid minus open claims, never below zero', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    $claims = new GuestClaimService;

    expect(gbBill($this, $f)->json('bill.totals'))->toMatchArray(['bill' => 18000, 'claimed' => 0, 'remaining' => 18000]);

    $claims->create($session, gbDevice('a'), 'Ada Eze', 8000);
    expect(gbBill($this, $f)->json('bill.totals'))->toMatchArray(['bill' => 18000, 'claimed' => 8000, 'remaining' => 10000]);

    $claims->create($session, gbDevice('b'), 'Bola Ade', 12000);
    expect(gbBill($this, $f)->json('bill.totals'))->toMatchArray(['bill' => 18000, 'claimed' => 20000, 'remaining' => 0]);
});

it('refuses a claim over the bill or a 6th open one, and lets a phone withdraw only its own', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    $claims = new GuestClaimService;

    expect(fn () => $claims->create($session, gbDevice('a'), 'Ada Eze', 18001))->toThrow(GuestRequestException::class, 'more than the bill');

    foreach (range(1, 5) as $n) {
        $claims->create($session, gbDevice('a'), 'Ada Eze', 100);
    }
    expect(fn () => $claims->create($session, gbDevice('a'), 'Ada Eze', 100))->toThrow(GuestRequestException::class, 'already have 5');

    $claim = GuestPaymentClaim::first();

    // Another phone: 403, nothing changes.
    $this->withCredentials()->withUnencryptedCookie('selum_gd', gbDevice('z'))
        ->postJson('/m/'.$f['t5']->qr_token.'/claims/'.$claim->id.'/withdraw', [])
        ->assertStatus(403);
    expect($claim->fresh()->status)->toBe('open');

    $this->withCredentials()->withUnencryptedCookie('selum_gd', gbDevice('a'))
        ->postJson('/m/'.$f['t5']->qr_token.'/claims/'.$claim->id.'/withdraw', [])
        ->assertOk();
    expect($claim->fresh()->status)->toBe('withdrawn');
    expect($claim->fresh()->status_set_at)->not->toBeNull();

    // Over HTTP too, with a friendly error.
    $this->withCredentials()->withUnencryptedCookie('selum_gd', gbDevice('c'))
        ->postJson('/m/'.$f['t5']->qr_token.'/claims', ['payer_name' => 'A', 'amount' => 100])
        ->assertStatus(422)->assertJson(['code' => 'bad_name']);
});

it('pays through the wrapper and settles claims: matched with a transfer line, unmatched without, untouched on failure', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    (new GuestClaimService)->create($session, gbDevice('a'), 'Ada Eze', 8000);
    $pay = new GuestTablePaymentService;

    // A failed payment (lines short of the bill) changes nothing.
    expect(fn () => $pay->pay($session, [['method' => 'cash', 'amount' => 100]], $f['emeka']))->toThrow(Exception::class, 'short');
    expect(GuestPaymentClaim::sole()->status)->toBe('open');
    expect(Order::where('status', 'paid')->count())->toBe(0);

    $total = $pay->pay($session, [
        ['method' => 'transfer', 'amount' => 8000, 'payer_reference' => 'Ada Eze'],
        ['method' => 'cash', 'amount' => 10000],
    ], $f['emeka']);

    expect($total)->toBe(18000.0);
    expect(GuestPaymentClaim::sole()->status)->toBe('matched');
    expect(OrderPayment::where('method', 'transfer')->value('payer_reference'))->toBe('Ada Eze');

    // Cash only on the next round: the open claim is unmatched (D12).
    gbOrder($f['t5'], 'served', [['Chapman', 1, 2500]], $f['emeka']);
    (new GuestClaimService)->create($session, gbDevice('a'), 'Ada Eze', 2500);
    $pay->pay($session, [['method' => 'cash', 'amount' => 2500]], $f['emeka']);
    expect(GuestPaymentClaim::latest('id')->first()->status)->toBe('unmatched');
    expect(fn () => GuestPaymentClaim::first()->update(['status' => 'open']))->toThrow(LogicException::class);
});

it('leaves FastMarkPaidService working exactly as before for an ordinary table (regression)', function () {
    $f = gbFixture();
    $orders = collect([gbOrder($f['t3'], 'served', [['Gin', 1, 3000]]), gbOrder($f['t3'], 'served', [['Tonic', 2, 500]])]);

    $paid = (new FastMarkPaidService)->payWithMethods($orders, [['method' => 'cash', 'amount' => 1000], ['method' => 'pos', 'amount' => 3000]], $f['emeka']);

    expect($paid)->toBe(4000.0);
    expect(Order::where('table_id', $f['t3']->id)->pluck('status')->unique()->all())->toBe(['paid']);
    expect(OrderPayment::count())->toBe(3);
    expect(file_get_contents(app_path('Services/Guest/GuestTablePaymentService.php')))->toContain('->payWithMethods($orders, $lines, $waiter)');
});

it('pre-fills Mark Paid from the claims: a transfer line for their total with the names, capped at the bill', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    $claims = new GuestClaimService;
    $claims->create($session, gbDevice('a'), 'Ada Eze', 5000);
    $claims->create($session, gbDevice('b'), 'Bola Ade', 3000);

    expect(GuestTablePaymentService::prefill($session)['lines'])->toBe([
        ['method' => 'transfer', 'amount' => 8000.0, 'payer_reference' => 'Ada Eze, Bola Ade'],
        ['method' => 'cash', 'amount' => 10000.0, 'payer_reference' => null],
    ]);

    $claims->create($session, gbDevice('c'), str_repeat('Chinonso ', 6), 15000);
    $prefill = GuestTablePaymentService::prefill($session);
    expect($prefill['lines'])->toHaveCount(1);
    expect($prefill['lines'][0]['amount'])->toBe(18000.0);
    expect(mb_strlen($prefill['lines'][0]['payer_reference']))->toBeLessThanOrEqual(120);

    // The kiosk: cash with open claims warns first (D12), then pays and marks them unmatched.
    Livewire::actingAs($f['emeka'])->test('pos')
        ->set('selectedTableId', (string) $f['t5']->id)
        ->call('markPaidFast', 'cash')
        ->assertSet('claimWarning.claimed', 23000.0)
        ->call('markPaidFast', 'cash', true)
        ->assertNotified('Paid: ₦18,000');

    expect(GuestPaymentClaim::pluck('status')->unique()->all())->toBe(['unmatched']);
});

it('moves a sitting: unpaid orders and live requests go, paid orders stay, and a row is written', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    $paid = gbOrder($f['t5'], 'paid', [['Paid Wine', 1, 4000]], $f['emeka']);
    $unpaid = Order::where('status', 'served')->sole();
    $live = GuestRequest::sole();
    $mover = new TableMoveService;

    // Destination with an unpaid order, or with an open sitting: refused.
    gbOrder($f['t3'], 'served', [['Someone Else', 1, 100]]);
    expect(fn () => $mover->move($session, $f['t3'], $f['emeka']))->toThrow(Exception::class, 'not free');
    gbSubmit($f, 'q', null, $f['t9']);
    expect(fn () => $mover->move($session, $f['t9'], $f['emeka']))->toThrow(Exception::class, 'not free');
    GuestTableSession::where('table_id', $f['t9']->id)->update(['closed_at' => now()]);

    // Not the table's waiter: refused.
    expect(fn () => $mover->move($session, $f['t9'], $f['tunde']))->toThrow(Exception::class, "Emeka's table");

    $this->travel(1)->minutes();
    $move = $mover->move($session, $f['t9'], $f['emeka']);

    expect($unpaid->fresh()->table_id)->toBe($f['t9']->id);
    expect($paid->fresh()->table_id)->toBe($f['t5']->id);
    expect($live->fresh()->table_id)->toBe($f['t9']->id);
    expect($session->fresh()->table_id)->toBe($f['t9']->id);
    expect($move->order_ids)->toContain($unpaid->id)->not->toContain($paid->id);
    expect($move->from_table_id)->toBe($f['t5']->id);
    expect(fn () => $move->delete())->toThrow(LogicException::class);

    // The bill still shows the paid order from Table 5 and nothing else of Table 9's.
    expect(collect(gbBill($this, $f, 'a', $f['t9'])->json('bill.sections.paid'))->pluck('name')->all())->toBe(['Paid Wine']);

    // A manager may move it on.
    $mover->move($session->fresh(), $f['t5'], $f['manager']);
    expect(TableMove::count())->toBe(2);
    expect($unpaid->fresh()->table_id)->toBe($f['t5']->id);
});

it('tells a phone on the old table where its sitting moved', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    (new TableMoveService)->move($session, $f['t9'], $f['emeka']);

    gbBill($this, $f)->assertOk()->assertJson(['table' => ['state' => 'moved', 'moved_to' => 'Table 9'], 'bill' => null]);
    $this->withUnencryptedCookie('selum_gd', gbDevice('a'))->get('/m/'.$f['t5']->qr_token)->assertOk()->assertSee('"moved_to":"Table 9"', false);

    // Another phone that never ordered there just sees no bill.
    gbBill($this, $f, 'x')->assertJson(['table' => ['state' => 'none']]);
});

it('closes a table only when nothing is unpaid or waiting, then starts a new sitting', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    $closer = new TableCloseService;

    expect(fn () => $closer->close($session, $f['emeka']))->toThrow(Exception::class, '₦18,000 unpaid (1 order)');
    expect(fn () => $closer->close($session, $f['emeka']))->toThrow(Exception::class, '1× Star Beer at the bar');
    expect($session->fresh()->isOpen())->toBeTrue();

    (new GuestTablePaymentService)->pay($session, [['method' => 'cash', 'amount' => 18000]], $f['emeka']);
    GuestRequestItem::query()->update(['status' => 'released']);

    expect(fn () => $closer->close($session, $f['tunde']))->toThrow(Exception::class, "Emeka's table");
    $closer->close($session, $f['emeka']);

    $closed = $session->fresh();
    expect($closed->closed_at)->not->toBeNull();
    expect($closed->closed_by_user_id)->toBe($f['emeka']->id);
    expect($closed->close_reason)->toBe('closed_by_waiter');
    gbBill($this, $f)->assertJson(['table' => ['state' => 'closed'], 'bill' => null]);

    gbSubmit($f);
    expect(GuestTableSession::open()->sole()->id)->not->toBe($session->id);
});

it('never lets guest:expire-stale close a sitting with an unpaid bill (D14/D20)', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    GuestRequestItem::query()->update(['status' => 'released']);
    $session->update(['last_activity_at' => now()->subHours(4)]);

    $this->artisan('guest:expire-stale')->assertSuccessful();
    expect($session->fresh()->isOpen())->toBeTrue();

    Order::query()->update(['status' => 'paid']);
    $this->artisan('guest:expire-stale')->assertSuccessful();
    expect($session->fresh()->close_reason)->toBe('auto_stale');
});

it('loads another round into the cart without sending anything (D21)', function () {
    $f = gbFixture();
    $request = gbSubmit($f, 'a', [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 3, 'chips' => ['Cold']],
        ['type' => 'product', 'id' => $f['malt']->id, 'qty' => 2],
    ]);
    (new GuestRequestService)->confirm($request, $f['emeka']);
    (new GuestBarReleaseService)->reduce($request->items()->where('item_id', $f['beer']->id)->sole(), 2, 'Out of stock');
    (new GuestBarReleaseService)->markReady($request);
    $f['malt']->update(['is_active' => false]);
    Cache::flush();
    $before = GuestRequest::count();

    $round = $this->withCredentials()->withUnencryptedCookie('selum_gd', gbDevice('a'))
        ->getJson('/m/'.$f['t5']->qr_token.'/round')->assertOk()->json();

    expect($round['lines'])->toHaveCount(1);
    expect($round['lines'][0])->toMatchArray(['key' => 'p'.$f['beer']->id, 'qty' => 2, 'chips' => ['Cold'], 'price' => 1000]);
    expect($round['skipped'])->toBe(['Maltina']);
    expect(GuestRequest::count())->toBe($before);
    expect((new GuestRoundService)->lastRound(GuestTableSession::sole(), gbDevice('b'))['lines'])->toBe([]);
});

it('limits waiter calls, answers them with one tap and no PIN, and expires them after 15 minutes (D22)', function () {
    $f = gbFixture();
    $calls = new GuestWaiterCallService;
    $call = fn (string $device, string $reason = 'ice') => $this->withCredentials()->withUnencryptedCookie('selum_gd', gbDevice($device))
        ->postJson('/m/'.$f['t5']->qr_token.'/calls', ['reason' => $reason, 'note' => $reason === 'other' ? 'Napkins please' : null]);

    $call('a')->assertCreated();
    $call('a', 'cups')->assertStatus(429)->assertJson(['message' => 'You just called — please wait a moment.']);
    $call('b', 'other')->assertCreated();
    $call('c')->assertCreated();
    $call('d')->assertStatus(429)->assertJson(['code' => 'call_busy']);
    expect(GuestWaiterCall::where('reason', 'other')->value('note'))->toBe('Napkins please');

    $first = GuestWaiterCall::orderBy('id')->first();
    Livewire::test('guest-orders-strip')
        ->assertSee('Table 5 · Ice')
        ->assertSee('Napkins please')
        ->call('acknowledgeCall', $first->id)
        ->assertNotified('Table 5 · on your way');
    expect($first->fresh()->status)->toBe('acknowledged');
    expect($first->fresh()->acknowledged_by_user_id)->toBeNull();
    expect($calls->acknowledge($first, null))->toBeFalse();

    $this->travel(16)->minutes();
    $this->artisan('guest:expire-stale')->assertSuccessful();
    expect(GuestWaiterCall::where('status', 'expired')->count())->toBe(2);
    expect($first->fresh()->status)->toBe('acknowledged');
});

it('creates zero session rows while a phone polls its bill (D9)', function () {
    $f = gbFixture();
    gbServedBill($f);
    DB::table('sessions')->delete();

    foreach (range(1, 3) as $n) {
        gbBill($this, $f)->assertOk();
    }
    $this->withCredentials()->withUnencryptedCookie('selum_gd', gbDevice())->getJson('/m/'.$f['t5']->qr_token.'/accounts')->assertOk();

    expect(DB::table('sessions')->count())->toBe(0);
});

it('keeps staff surnames, internal order ids and other tables out of the bill payload', function () {
    $f = gbFixture();
    gbServedBill($f);
    $order = Order::where('status', 'served')->sole();
    gbOrder($f['t9'], 'served', [['Other Table Wine', 1, 9000]], $f['tunde']);
    TransferAccount::create(['bank_name' => 'GTBank', 'account_name' => 'Selum Lounge', 'account_number' => '0123456789', 'active' => true]);

    $response = gbBill($this, $f)->assertOk();
    $json = $response->getContent();

    expect(str_contains($json, 'Obi'))->toBeFalse();
    expect(str_contains($json, 'Bello'))->toBeFalse();
    expect(str_contains($json, 'Other Table Wine'))->toBeFalse();
    expect(str_contains($json, $order->order_number))->toBeFalse();
    expect(collect($response->json('bill.sections'))->flatten(1)->every(fn ($line) => ! array_key_exists('id', $line) && ! array_key_exists('order_id', $line)))->toBeTrue();
});

it('publishes a public WebP copy of the logo on save, never the private original', function () {
    Storage::fake('local');
    $png = imagecreatetruecolor(900, 300);
    ob_start();
    imagepng($png);
    Storage::disk('local')->put('company-logos/logo.png', ob_get_clean());
    File::delete(BrandingLogo::publicPath());

    try {
        Company::updateOrCreate(['id' => 1], ['name' => 'Selum', 'logo_path' => 'company-logos/logo.png']);

        expect(is_file(BrandingLogo::publicPath()))->toBeTrue();
        [$width, , $type] = getimagesize(BrandingLogo::publicPath());
        expect($width)->toBe(400);
        expect($type)->toBe(IMAGETYPE_WEBP);
        expect(BrandingLogo::url())->toStartWith('/media/branding/logo.webp?v=');
        expect(is_file(public_path('storage/company-logos/logo.png')))->toBeFalse();
        expect($this->get('/storage/company-logos/logo.png')->getStatusCode())->toBeIn([403, 404]); // private: never served

        $f = gbFixture();
        $this->get('/m/'.$f['t5']->qr_token)->assertOk()->assertSee('/media/branding/logo.webp', false);
    } finally {
        File::delete(BrandingLogo::publicPath());
    }
});

it('lets the strip close a table with the PIN and lists what blocks it', function () {
    $f = gbFixture();
    $session = gbServedBill($f);

    Livewire::test('guest-orders-strip')
        ->assertSee('Guest tables')
        ->call('closeTable', $session->id, '4826')
        ->assertNotified("Couldn't close the table");
    expect($session->fresh()->isOpen())->toBeTrue();

    Livewire::test('guest-orders-strip')->call('moveTable', $session->id, $f['t9']->id, '4826')->assertNotified('Table 5 moved to Table 9');
    expect($session->fresh()->table_id)->toBe($f['t9']->id);
});

it('shows claim cards on the waiter strip and asks Same guests before the PIN', function () {
    $f = gbFixture();
    $session = gbServedBill($f);
    (new GuestClaimService)->create($session, gbDevice('a'), 'Ada Eze', 8000);

    Livewire::test('guest-orders-strip')->assertSee('Ada Eze says they paid ₦8,000 by transfer');

    $t3Request = gbSubmit($f, 'k', null, $f['t3']);
    gbOrder($f['t3'], 'served', [['Old Gin', 1, 7000]], null, now()->subHour());
    Livewire::test('guest-orders-strip')
        ->call('accept', $t3Request->id, '5937')
        ->assertNotified('Same guests?');
    expect($t3Request->fresh()->status)->toBe('pending');
    Livewire::test('guest-orders-strip')->call('accept', $t3Request->id, '5937', true)->assertNotified("{$t3Request->ref} accepted");
});
