<?php

use App\Filament\Pages\ReceiveTransfers;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Receiving used to be answerable to a role and a warehouse, never to a
 * shift: every bartender account saw the same bar list forever, on duty or
 * not, and any of them could close out a line. Stock is credited to the
 * warehouse the instant that happens, so an off-shift bartender receiving
 * a delivery silently moved it onto the count of whoever was actually on
 * duty — and, going the other way, left the on-duty bartender unable to
 * end their shift (MyCount blocks on unreceived transfers) over a delivery
 * that was never theirs to take.
 *
 * Two rules now, both enforced in StockTransferService so the Livewire
 * page, the whole-transfer route and bulk receive cannot disagree:
 * a custodian must be on duty, and may only receive into their own store.
 */
function grantReceivePage(string $role): void
{
    PagePermission::firstOrCreate(
        ['page_class' => ReceiveTransfers::class, 'role_name' => $role],
        ['page_class' => ReceiveTransfers::class, 'page_name' => 'Receive Transfers', 'role_name' => $role]
    );
}

function onShift(User $user, string $type): Shift
{
    return Shift::create([
        'user_id' => $user->id, 'type' => $type,
        'started_at' => now()->subHours(2), 'status' => 'active',
    ]);
}

function custodianWorld(): array
{
    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $main->id, 'quantity' => 100]);

    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    return compact('main', 'bar', 'kitchen', 'beer', 'storekeeper');
}

function barTransfer(array $world, float $qty = 10): StockTransfer
{
    return app(StockTransferService::class)->createTransfer(
        $world['main']->id, $world['bar']->id, $world['storekeeper']->id,
        [['product_id' => $world['beer']->id, 'quantity' => $qty]]
    );
}

function makeBartender(): User
{
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    return $bartender;
}

it('hides the Receive Transfers page entirely from a bartender who is not on shift', function () {
    custodianWorld();
    grantReceivePage('bartender');
    $bartender = makeBartender();

    Livewire::actingAs($bartender)
        ->test(ReceiveTransfers::class)
        ->assertForbidden();
});

it('opens the page again the moment that same bartender starts a shift', function () {
    custodianWorld();
    grantReceivePage('bartender');
    $bartender = makeBartender();

    $this->actingAs($bartender);
    expect(ReceiveTransfers::canAccess())->toBeFalse();

    onShift($bartender, 'bartender');

    expect(ReceiveTransfers::canAccess())->toBeTrue();
});

it('refuses a line receipt from an off-shift bartender, leaving the stock in transit', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);
    $bartender = makeBartender();

    expect(fn () => app(StockTransferService::class)->receiveTransferLine($transfer->items->first(), 10, $bartender->id))
        ->toThrow(Exception::class, 'You are not on shift');

    expect($transfer->fresh()->status)->toBe('pending');
    expect(InventoryItem::where('warehouse_id', $world['bar']->id)->count())->toBe(0);
});

it('hard-blocks a chef from receiving a bar line', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);

    $chef = User::factory()->create();
    $chef->assignRole(Role::firstOrCreate(['name' => 'chef']));
    onShift($chef, 'chef');

    expect(fn () => app(StockTransferService::class)->receiveTransferLine($transfer->items->first(), 10, $chef->id))
        ->toThrow(Exception::class, 'not to your own store');

    expect($transfer->fresh()->status)->toBe('pending');
});

it('hard-blocks a bartender from receiving a kitchen line', function () {
    $world = custodianWorld();

    $food = Category::create(['name' => 'Food', 'type' => 'food']);
    $rice = Product::create(['name' => 'Rice', 'price' => 800, 'category_id' => $food->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $rice->id, 'warehouse_id' => $world['main']->id, 'quantity' => 40]);

    $kitchenTransfer = app(StockTransferService::class)->createTransfer(
        $world['main']->id, $world['kitchen']->id, $world['storekeeper']->id,
        [['product_id' => $rice->id, 'quantity' => 5]]
    );

    $bartender = makeBartender();
    onShift($bartender, 'bartender');

    expect(fn () => app(StockTransferService::class)->receiveTransferLine($kitchenTransfer->items->first(), 5, $bartender->id))
        ->toThrow(Exception::class, 'not to your own store');

    expect($kitchenTransfer->fresh()->status)->toBe('pending');
});

it('stamps the receiving shift on the line so the stock is answerable to a custodian, not just a warehouse', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);
    $bartender = makeBartender();
    $shift = onShift($bartender, 'bartender');

    app(StockTransferService::class)->receiveTransferLine($transfer->items->first(), 10, $bartender->id);

    $line = $transfer->items->first()->fresh();
    expect($line->received_shift_id)->toBe($shift->id);
    expect($line->receivedShift->user_id)->toBe($bartender->id);
});

it('leaves the receiving shift null for a storekeeper, who holds no custodian shift', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);

    app(StockTransferService::class)->receiveTransferLine($transfer->items->first(), 10, $world['storekeeper']->id);

    expect($transfer->items->first()->fresh()->received_shift_id)->toBeNull();
});

/**
 * A stale shift (open past Shift::STALE_AFTER_HOURS) is a forgotten
 * sign-out, not a person standing at the bar — it must not satisfy this
 * gate any more than it satisfies the sales gate in OrderSplitter.
 */
it('does not treat an abandoned, stale shift as being on duty', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);
    $bartender = makeBartender();

    Shift::create([
        'user_id' => $bartender->id, 'type' => 'bartender',
        'started_at' => now()->subHours(Shift::STALE_AFTER_HOURS + 2), 'status' => 'active',
    ]);

    expect(fn () => app(StockTransferService::class)->receiveTransferLine($transfer->items->first(), 10, $bartender->id))
        ->toThrow(Exception::class, 'You are not on shift');
});

it('refuses the whole-transfer receive route to an off-shift bartender', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);
    grantReceivePage('bartender');
    $bartender = makeBartender();

    $this->actingAs($bartender)
        ->postJson("/stock-transfers/{$transfer->id}/receive")
        ->assertStatus(422);

    expect($transfer->fresh()->status)->toBe('pending');
});

it('refuses bulk receive to an off-shift bartender, reporting it as a per-transfer failure', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);
    grantReceivePage('bartender');
    $bartender = makeBartender();

    $response = $this->actingAs($bartender)
        ->postJson('/stock-transfers/bulk-receive', ['transfer_ids' => [$transfer->id]]);

    $response->assertOk();
    expect($response->json('successful'))->toBe(0);
    expect($response->json('failed'))->toBe(1);
    expect($transfer->fresh()->status)->toBe('pending');
});

/**
 * The all-or-nothing path moved stock but never wrote a single receipt
 * field, so anything bulk-received landed in history with a blank
 * received column, no receiver, and no date at all.
 */
it('records a full receipt on every line when a transfer is bulk-received', function () {
    $world = custodianWorld();
    $transfer = barTransfer($world);
    grantReceivePage('bartender');
    $bartender = makeBartender();
    $shift = onShift($bartender, 'bartender');

    $this->actingAs($bartender)
        ->postJson('/stock-transfers/bulk-receive', ['transfer_ids' => [$transfer->id]])
        ->assertOk();

    $line = $transfer->items->first()->fresh();
    expect($line->outcome)->toBe('received_full');
    expect((float) $line->received_quantity)->toBe(10.0);
    expect($line->received_by)->toBe($bartender->id);
    expect($line->received_at)->not->toBeNull();
    expect($line->received_shift_id)->toBe($shift->id);
});
