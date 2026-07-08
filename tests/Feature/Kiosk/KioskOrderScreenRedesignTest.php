<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\KioskDeviceService;
use App\Services\PinAuthService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * The kiosk touchscreen (1920x1080) gets a dedicated fixed-viewport,
 * touch-first shell — but pos.blade.php is also embedded in the Filament
 * admin Sales page and rendered for staff-phone (trusted device, no
 * kiosk_device_id session key). Both of those must keep rendering the
 * original layout untouched; only an actual registered kiosk device
 * session should see the new shell.
 */
function seedRedesignFixtures(): array
{
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'PSB']);

    return compact('beer', 'table');
}

it('renders the touch-first kiosk shell, including Place Order, for an actual kiosk device session', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('Place Order')
        ->assertSee('Select a Table')
        ->assertSee('Outstanding');
});

it('keeps the original layout for the admin Sales page, with no kiosk-only markup', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();

    Livewire::actingAs($user)
        ->test('pos', ['table_id' => $table->id])
        ->assertDontSee('Place Order')
        ->assertDontSee('Select a Table')
        ->assertSee('Order');
});

it('keeps the original mobile/desktop layout for staff-phone, which is not a kiosk device', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['trusted_device_user_id' => $waiter->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertDontSee('Place Order')
        ->assertDontSee('Select a Table')
        ->assertSee('Order');
});

/**
 * Regression test: a previous version of this footer required tapping
 * "Mark Paid" once to reveal the Cash/POS/Transfer buttons underneath it
 * (an x-show toggle), which read as "the button does nothing" when that
 * reveal step wasn't noticed. The methods must be immediately present in
 * the rendered markup, not gated behind a client-side toggle a tap has to
 * discover first.
 */
it('shows the Cash/POS/Transfer buttons immediately for an outstanding order, no reveal tap required', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer, 'table' => $table] = seedRedesignFixtures();

    \App\Models\Order::create([
        'order_number' => 'ORD-OUTSTANDING',
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'served',
        'destination' => 'bar',
        'total_amount' => 500,
    ])->items()->create([
        'product_id' => $beer->id,
        'product_name' => $beer->name,
        'item_type' => 'product',
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
    ]);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('Outstanding')
        ->assertSeeHtml('wire:click="markPaidFast(\'cash\')"')
        ->assertSeeHtml('wire:click="markPaidFast(\'pos\')"')
        ->assertSeeHtml('wire:click="markPaidFast(\'transfer\')"')
        ->assertDontSeeHtml('x-show="showMarkPaidMethods"');
});

/**
 * Regression coverage for a real gap: the only place that could move an
 * order from 'ready' to 'served' — required before Mark Paid or Pay will
 * do anything at all — was an admin-only Filament page (TableDetail),
 * completely unreachable from the kiosk. A waiter using only the kiosk
 * had no way to ever get an order into a payable state, so payment
 * looked broken even though nothing was actually failing — it was
 * correctly blocked by a check nobody had a way to satisfy.
 */
it('shows a Confirm Served banner on the kiosk for a ready order, and confirming it unlocks Mark Paid', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer, 'table' => $table] = seedRedesignFixtures();

    $order = \App\Models\Order::create([
        'order_number' => 'ORD-READY-1',
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'ready',
        'destination' => 'bar',
        'total_amount' => 500,
    ]);
    $order->items()->create([
        'product_id' => $beer->id,
        'product_name' => $beer->name,
        'item_type' => 'product',
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
    ]);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    $component = Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('Confirm Served')
        ->assertSee('Bar Order');

    // Before confirmation: Mark Paid must not be reachable at all (the
    // footer shows "Outstanding" only once existingCount > 0 — a ready
    // order still counts as an existing item, so the button is visible,
    // but the actual server-side action must still refuse to pay it).
    $component->call('markPaidFast', 'cash');
    expect($order->fresh()->status)->toBe('ready');
    expect(\App\Models\OrderPayment::count())->toBe(0);

    $component->call('confirmServed', $order->id)
        ->assertDontSee('Confirm Served');

    expect($order->fresh()->status)->toBe('served');
    expect($order->fresh()->served_at)->not->toBeNull();

    $component->call('markPaidFast', 'cash');

    expect($order->fresh()->status)->toBe('paid');
    expect(\App\Models\OrderPayment::count())->toBe(1);
    expect($table->fresh()->status)->toBe('available');
});

it('does not show the Confirm Served banner when nothing is ready', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertDontSee('Confirm Served');
});

it('groups the kiosk table picker by table location', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();
    TableModel::create(['name' => 'BB 1', 'capacity' => 2, 'status' => 'available', 'location' => 'BB']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('PSB')
        ->assertSee('BB')
        ->assertSee('Take Away');
});

/**
 * Blind counting (Inventory Integrity package) requires that expected
 * quantities are never casually visible to whoever will later be counted
 * against them — so the exact figure must not appear anywhere in the kiosk
 * order page's rendered output, not just be visually hidden by CSS. This
 * asserts against the full Livewire response body (the same HTML/payload a
 * curious user could inspect via view-source or the network tab), not just
 * what a screenshot would show.
 */
it('never renders an exact stock quantity anywhere on the kiosk order page, healthy or low', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $healthyStock = Product::create(['name' => 'Healthy Stock Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $lowStock = Product::create(['name' => 'Low Stock Beer', 'price' => 600, 'category_id' => $category->id, 'is_active' => true]);
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    // A distinctive, unlikely-to-collide quantity for the "healthy" product,
    // and a below-threshold quantity for the "low" one.
    InventoryItem::create(['product_id' => $healthyStock->id, 'warehouse_id' => $bar->id, 'quantity' => 173]);
    InventoryItem::create(['product_id' => $lowStock->id, 'warehouse_id' => $bar->id, 'quantity' => 3]);

    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'PSB']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('Healthy Stock Beer')
        ->assertSee('Low Stock Beer')
        ->assertSee('Low') // the discrete low-stock badge is fine
        ->assertDontSee('173') // but never the raw healthy count
        ->assertDontSee('Bar:'); // and never the old "Bar: N" label at all
});

it('shows a disabled, non-tappable SOLD OUT card and rejects the order server-side if attempted anyway', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $soldOut = Product::create(['name' => 'Sold Out Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $soldOut->id, 'warehouse_id' => $bar->id, 'quantity' => 0]);

    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'PSB']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('Sold out')
        ->assertDontSee("addProductToCart({$soldOut->id},"); // no click handler attached at all

    // Even a direct call bypassing the disabled UI must still be rejected —
    // the card being non-tappable is a UX nicety, not the actual guard.
    Livewire::test('pos', ['table_id' => $table->id])
        ->call('checkout', [
            $soldOut->id => ['name' => $soldOut->name, 'price' => $soldOut->price, 'quantity' => 1],
        ]);

    expect(\App\Models\Order::count())->toBe(0);
    expect(\App\Models\OrderItem::count())->toBe(0);
});

it('wires Clear to a client-only action that cannot touch already-sent Existing Items', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer, 'table' => $table] = seedRedesignFixtures();

    \App\Models\Order::create([
        'order_number' => 'ORD-CLEAR-TEST',
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'pending',
        'destination' => 'bar',
        'total_amount' => 500,
    ])->items()->create([
        'product_id' => $beer->id,
        'product_name' => $beer->name,
        'item_type' => 'product',
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
    ]);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    // clearNewItems() only ever mutates the Alpine `cart` object (unsent
    // items) — it has no wire:click/$wire.call, so there is no server round
    // trip for it to reach $existingItems through in the first place. This
    // is the part of the guarantee that can be verified without executing
    // Alpine JS in a Pest test; the rest is enforced by code review.
    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('Existing Items')
        ->assertSee($beer->name)
        ->assertSee('clearNewItems()')
        ->assertDontSee('wire:click="clearNewItems');
});
