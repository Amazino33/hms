<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StaffDebt;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\KitchenOrderService;
use App\Services\PinAuthService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * The KDS board is a read-and-act VIEW over KitchenOrderService — it
 * changes nothing about how or when stock deducts. Readiness stays
 * order-level (there is no per-item readiness field or service anywhere in
 * this app — see KitchenOrderService's own docblock); the board's only new
 * writes are kds_picked_up_at/kds_picked_up_by, distinct from the existing
 * porter-delivery picked_up_at/picked_up_by.
 */
function seedKdsKitchenOrder(string $productName = 'Jollof Rice'): array
{
    $table = TableModel::create(['name' => 'Table '.uniqid(), 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $product = Product::create(['name' => $productName, 'price' => 1500, 'category_id' => $category->id, 'is_active' => true]);

    $waiter = User::factory()->create(['name' => 'Amaka']);

    $order = Order::create([
        'order_number' => 'ORD-'.uniqid(),
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'pending',
        'destination' => 'kitchen',
        'total_amount' => 1500,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'item_type' => 'product',
        'quantity' => 2,
        'unit_price' => 1500,
        'subtotal' => 3000,
    ]);

    return compact('table', 'product', 'waiter', 'order');
}

function seedKdsRoomBooking(string $roomNumber): array
{
    $room = \App\Models\Room::create(['number' => $roomNumber, 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'occupied', 'housekeeping' => 'clean']);
    $guest = \App\Models\Guest::create(['name' => 'Room Guest', 'phone' => '0800'.uniqid()]);
    $booking = \App\Models\Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'total_price' => 15000, 'status' => 'checked_in',
    ]);

    return compact('room', 'guest', 'booking');
}

function loginActiveCook(string $pin = '5739'): User
{
    $cook = User::factory()->create(['name' => 'Chef Tunde']);
    (new PinAuthService)->setPin($cook, $pin);
    Auth::guard('staff_pin')->login($cook);

    return $cook;
}

it('lists only active kitchen orders FIFO, excludes bar orders, respects the tile cap and reports waiting count', function () {
    Setting::create(['key' => 'kds_tile_cap', 'value' => '2', 'type' => 'string']);

    ['order' => $first] = seedKdsKitchenOrder('First Dish');
    $first->update(['created_at' => now()->subMinutes(10)]);

    ['order' => $second] = seedKdsKitchenOrder('Second Dish');
    $second->update(['created_at' => now()->subMinutes(5)]);

    ['order' => $third] = seedKdsKitchenOrder('Third Dish');
    $third->update(['created_at' => now()->subMinutes(1)]);

    // A bar order must never appear on the kitchen board.
    $barTable = TableModel::create(['name' => 'Bar Table', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $barCategory = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $barCategory->id, 'is_active' => true]);
    $barOrder = Order::create([
        'order_number' => 'ORD-BAR', 'table_id' => $barTable->id, 'user_id' => User::factory()->create()->id,
        'status' => 'pending', 'destination' => 'bar', 'total_amount' => 500,
    ]);
    $barOrder->items()->create(['product_id' => $beer->id, 'product_name' => $beer->name, 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    $component = Livewire::test('kds-board');
    $tickets = $component->instance()->with()['tickets'];
    $waitingCount = $component->instance()->with()['waitingCount'];

    expect($tickets)->toHaveCount(2);
    // FIFO: oldest first.
    expect($tickets[0]['order_number'])->toBe($first->order_number);
    expect($tickets[1]['order_number'])->toBe($second->order_number);
    expect($waitingCount)->toBe(1);
    expect(collect($tickets)->pluck('order_number'))->not->toContain($barOrder->order_number);
});

it('produces an identical stock-deduction effect to the existing KitchenDisplay path when marking a room order ready via the KDS', function () {
    // Control: a room order marked ready through the existing, untouched
    // KitchenDisplay page.
    $warehouse = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $controlProduct = Product::create(['name' => 'Control Dish', 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $controlProduct->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    ['booking' => $booking] = seedKdsRoomBooking('101');
    $guestUser = User::factory()->create();

    $controlOrder = Order::create([
        'order_number' => 'ORD-CONTROL', 'user_id' => $guestUser->id, 'booking_id' => $booking->id,
        'status' => 'pending', 'destination' => 'kitchen', 'total_amount' => 1000,
    ]);
    $controlOrder->items()->create(['product_id' => $controlProduct->id, 'product_name' => $controlProduct->name, 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);

    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    Livewire::actingAs($admin)->test(\App\Filament\Pages\KitchenDisplay::class)->call('markAsReady', $controlOrder->id);

    $controlStock = (float) InventoryItem::where('product_id', $controlProduct->id)->where('warehouse_id', $warehouse->id)->value('quantity');
    $controlTxnCount = InventoryTransaction::where('product_id', $controlProduct->id)->where('type', 'sale')->count();

    // Test subject: an identical room order marked ready via the KDS board.
    $testProduct = Product::create(['name' => 'Test Dish', 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $testProduct->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $testOrder = Order::create([
        'order_number' => 'ORD-TEST', 'user_id' => $guestUser->id, 'booking_id' => $booking->id,
        'status' => 'pending', 'destination' => 'kitchen', 'total_amount' => 1000,
    ]);
    $testOrder->items()->create(['product_id' => $testProduct->id, 'product_name' => $testProduct->name, 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);

    $cook = loginActiveCook();
    Livewire::test('kds-board')->call('markReady', $testOrder->id);

    $testStock = (float) InventoryItem::where('product_id', $testProduct->id)->where('warehouse_id', $warehouse->id)->value('quantity');
    $testTxnCount = InventoryTransaction::where('product_id', $testProduct->id)->where('type', 'sale')->count();

    // Both started at 20, both sold 1 — the resulting stock level and
    // transaction count must match exactly, proving the KDS went through
    // the same deduction path as the existing, untouched page.
    expect($testStock)->toBe($controlStock);
    expect($testTxnCount)->toBe($controlTxnCount);
    expect($testOrder->fresh()->status)->toBe('ready');
});

it('deducts stock exactly once per markReady call — no per-item double deduction and no order-level double deduction on a repeat attempt', function () {
    $warehouse = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $product = Product::create(['name' => 'Egusi Soup', 'price' => 2000, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

    ['booking' => $booking] = seedKdsRoomBooking('102');
    $guestUser = User::factory()->create();

    $order = Order::create([
        'order_number' => 'ORD-MULTI', 'user_id' => $guestUser->id, 'booking_id' => $booking->id,
        'status' => 'pending', 'destination' => 'kitchen', 'total_amount' => 4000,
    ]);
    // Two lines on one order — deduction still fires once per order, not per line.
    $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 2000, 'subtotal' => 2000]);
    $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 2000, 'subtotal' => 2000]);

    loginActiveCook();
    $component = Livewire::test('kds-board');
    $component->call('markReady', $order->id);

    expect(InventoryTransaction::where('product_id', $product->id)->where('type', 'sale')->count())->toBe(2);

    // A second markReady on the same (now-ready) order must not deduct again.
    $component->call('markReady', $order->id);

    expect(InventoryTransaction::where('product_id', $product->id)->where('type', 'sale')->count())->toBe(2);
});

it('shows the ready rollup only after markReady, never before', function () {
    ['order' => $order] = seedKdsKitchenOrder();
    loginActiveCook();

    $before = Livewire::test('kds-board')->instance()->with()['tickets'];
    expect(collect($before)->firstWhere('id', $order->id)['is_ready'])->toBeFalse();

    Livewire::test('kds-board')->call('markReady', $order->id);

    $after = Livewire::test('kds-board')->instance()->with()['tickets'];
    expect(collect($after)->firstWhere('id', $order->id)['is_ready'])->toBeTrue();
});

it('sets kds_picked_up_at/by on pickup and clears the tile from the board, without touching the existing porter pickup columns', function () {
    ['order' => $order] = seedKdsKitchenOrder();
    $cook = loginActiveCook();

    Livewire::test('kds-board')->call('markReady', $order->id);
    Livewire::test('kds-board')->call('markPickedUp', $order->id);

    $fresh = $order->fresh();
    expect($fresh->kds_picked_up_at)->not->toBeNull();
    expect($fresh->kds_picked_up_by)->toBe($cook->id);
    expect($fresh->picked_up_at)->toBeNull();
    expect($fresh->picked_up_by)->toBeNull();

    $tickets = Livewire::test('kds-board')->instance()->with()['tickets'];
    expect(collect($tickets)->pluck('id'))->not->toContain($order->id);
});

it('refuses pickup until every item on the order is ready', function () {
    ['order' => $order] = seedKdsKitchenOrder();
    loginActiveCook();

    // Never marked ready.
    Livewire::test('kds-board')->call('markPickedUp', $order->id);

    expect($order->fresh()->kds_picked_up_at)->toBeNull();
});

it('rejects markReady and markPickedUp server-side when no active cook is signed in, structurally not just via a hidden button', function () {
    ['order' => $order] = seedKdsKitchenOrder();

    // No loginActiveCook() call at all — no staff_pin session exists.
    expect(Auth::guard('staff_pin')->check())->toBeFalse();

    Livewire::test('kds-board')->call('markReady', $order->id);
    expect($order->fresh()->status)->toBe('pending');

    Livewire::test('kds-board')->call('markPickedUp', $order->id);
    expect($order->fresh()->kds_picked_up_at)->toBeNull();
});

it('attributes markReady and markPickedUp to whoever the active cook actually is', function () {
    ['order' => $order] = seedKdsKitchenOrder();
    $cook = loginActiveCook();

    Livewire::test('kds-board')->call('markReady', $order->id);
    expect($order->fresh()->processed_by_user_id)->toBe($cook->id);

    Livewire::test('kds-board')->call('markPickedUp', $order->id);
    expect($order->fresh()->kds_picked_up_by)->toBe($cook->id);
});

it('never invokes StaffDebt, the handover-count module, or any bar-destination logic', function () {
    ['order' => $order] = seedKdsKitchenOrder();
    loginActiveCook();

    Livewire::test('kds-board')->call('markReady', $order->id);
    Livewire::test('kds-board')->call('markPickedUp', $order->id);

    expect(StaffDebt::count())->toBe(0);
    expect(\App\Models\CountSession::count())->toBe(0);
    expect(\App\Models\Shift::count())->toBe(0);
});

it('computes elapsed time from the server-side created_at timestamp, independent of any client-supplied time', function () {
    ['order' => $order] = seedKdsKitchenOrder();
    $order->update(['created_at' => now()->subMinutes(20)]);

    $tickets = Livewire::test('kds-board')->instance()->with()['tickets'];
    $ticket = collect($tickets)->firstWhere('id', $order->id);

    // ~1200 seconds elapsed, computed purely server-side from created_at —
    // nothing in the request payload influences this figure.
    expect($ticket['elapsed_seconds'])->toBeGreaterThanOrEqual(1195);
    expect($ticket['elapsed_seconds'])->toBeLessThanOrEqual(1205);
});

it('reads the amber/red thresholds and poll interval from settings, not a hardcoded value', function () {
    Setting::create(['key' => 'kds_amber_minutes', 'value' => '7', 'type' => 'string']);
    Setting::create(['key' => 'kds_red_minutes', 'value' => '13', 'type' => 'string']);
    Setting::create(['key' => 'kds_poll_seconds', 'value' => '9', 'type' => 'string']);

    $data = Livewire::test('kds-board')->instance()->with();

    expect($data['amberSeconds'])->toBe(7 * 60);
    expect($data['redSeconds'])->toBe(13 * 60);
    expect($data['pollSeconds'])->toBe(9);
});
