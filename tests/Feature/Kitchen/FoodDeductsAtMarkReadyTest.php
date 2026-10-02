<?php

use App\Filament\Pages\KitchenDisplay;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\KitchenWasteLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Room;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\InventoryService;
use App\Services\KitchenOrderService;
use App\Services\OrderSplitter;
use App\Services\ReservationService;
use App\Services\RoomOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Phase 0D — kitchen food leaves the shelf at Mark Ready, on every path
 * that reaches the kitchen screen, through the one entry point
 * (KitchenOrderService::markReady()). Cooked food is never restocked.
 * See docs/audits/food-deduction-timing-verification.md.
 */
function mrFixture(float $riceInKitchen = 10, float $beerAtBar = 20): array
{
    // Bar is the first consumer warehouse and Kitchen the second — the same
    // convention InventoryService::getBar/KitchenWarehouseId() resolves by.
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $food = Category::create(['name' => 'Food', 'type' => 'food']);
    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $jollof = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-MR-'.uniqid(), 'sale_price' => 2500, 'category_id' => $food->id, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-MR-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 300, 'category' => 'Grains']);
    Recipe::create(['menu_item_id' => $jollof->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 2]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => $riceInKitchen]);

    $beer = Product::create(['name' => 'Star Beer', 'price' => 800, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => $beerAtBar]);

    $waiter = User::factory()->create();
    $waiterShift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $table = TableModel::create(['name' => 'Table MR '.uniqid(), 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    return compact('bar', 'kitchen', 'jollof', 'rice', 'beer', 'waiter', 'waiterShift', 'chef', 'table');
}

function mrRice(array $f): float
{
    return (float) IngredientInventoryItem::where('ingredient_id', $f['rice']->id)->where('warehouse_id', $f['kitchen']->id)->value('quantity');
}

function mrBeer(array $f): float
{
    return (float) InventoryItem::where('product_id', $f['beer']->id)->where('warehouse_id', $f['bar']->id)->value('quantity');
}

/** A normal dine-in "Order" tap: 1x Jollof (kitchen) + 2x Star Beer (bar). */
function mrPlaceDineIn(array $f, int $jollofQty = 1, int $beerQty = 2): array
{
    $cart = ['menu_'.$f['jollof']->id => ['name' => 'Jollof Rice', 'price' => 2500, 'quantity' => $jollofQty]];

    if ($beerQty > 0) {
        $cart[(string) $f['beer']->id] = ['name' => 'Star Beer', 'price' => 800, 'quantity' => $beerQty];
    }

    $orders = collect((new OrderSplitter)->handle($cart, $f['table']->id, $f['waiter']->id, [
        'status' => 'pending',
        'shift_id' => $f['waiterShift']->id,
    ]));

    return [
        'kitchen' => $orders->firstWhere('destination', 'kitchen'),
        'bar' => $orders->firstWhere('destination', 'bar'),
    ];
}

function mrUsageRows(Order $order): int
{
    return IngredientTransaction::where('reference', "order:{$order->id}")->where('type', 'usage')->count();
}

it('creates a dine-in food order without deducting any ingredients', function () {
    $f = mrFixture();
    ['kitchen' => $kitchenOrder] = mrPlaceDineIn($f);

    expect(mrRice($f))->toBe(10.0);
    expect(mrUsageRows($kitchenOrder))->toBe(0);
    expect($kitchenOrder->fresh()->stock_deducted_at)->toBeNull();
});

it('does not deduct when the waiter sends food from the real POS screen either', function () {
    $f = mrFixture();

    Livewire::actingAs($f['waiter'])
        ->test('pos')
        ->set('selectedTableId', (string) $f['table']->id)
        ->call('checkout', ['menu_'.$f['jollof']->id => ['name' => 'Jollof Rice', 'price' => 2500, 'qty' => 2, 'type' => 'menu_item', 'menu_item_id' => $f['jollof']->id]]);

    $kitchenOrder = Order::where('destination', 'kitchen')->sole();
    expect(mrRice($f))->toBe(10.0);
    expect(IngredientTransaction::count())->toBe(0);
    expect($kitchenOrder->stock_deducted_at)->toBeNull();
});

it('deducts the ingredients exactly once at Mark Ready and stamps stock_deducted_at', function () {
    $f = mrFixture();
    ['kitchen' => $kitchenOrder] = mrPlaceDineIn($f, jollofQty: 2);

    (new KitchenOrderService)->markReady($kitchenOrder->id, $f['chef']->id);

    expect(mrRice($f))->toBe(6.0); // 2 dishes x 2kg
    expect(mrUsageRows($kitchenOrder))->toBe(1);

    $kitchenOrder->refresh();
    expect($kitchenOrder->status)->toBe('ready');
    expect($kitchenOrder->stock_deducted_at)->not->toBeNull();
    expect(IngredientTransaction::where('reference', "order:{$kitchenOrder->id}")->value('user_id'))->toBe($f['waiter']->id);
});

it('never deducts twice when Mark Ready is tapped again or retried', function () {
    $f = mrFixture();
    ['kitchen' => $kitchenOrder] = mrPlaceDineIn($f);
    $service = new KitchenOrderService;

    $service->markReady($kitchenOrder->id, $f['chef']->id);

    expect(fn () => $service->markReady($kitchenOrder->id, $f['chef']->id))->toThrow(ModelNotFoundException::class);

    // The admin Kitchen Display's double tap: a message, not an error page.
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    Livewire::actingAs($admin)->test(KitchenDisplay::class)->call('markAsReady', $kitchenOrder->id)->assertOk();

    expect(mrRice($f))->toBe(8.0);
    expect(mrUsageRows($kitchenOrder))->toBe(1);
});

it('moves no stock at all when food is cancelled before Mark Ready', function () {
    $f = mrFixture();
    ['kitchen' => $kitchenOrder] = mrPlaceDineIn($f, beerQty: 0);

    $kitchenOrder->update(['status' => 'cancelled', 'cancellation_reason' => 'Guest changed mind']);

    expect(mrRice($f))->toBe(10.0);
    expect(IngredientTransaction::count())->toBe(0);
    expect(InventoryTransaction::count())->toBe(0);
    expect(KitchenWasteLog::count())->toBe(0);
});

it('never restocks cooked food cancelled after Mark Ready, and records it as kitchen waste', function () {
    $f = mrFixture();
    ['kitchen' => $kitchenOrder] = mrPlaceDineIn($f, jollofQty: 2, beerQty: 0);
    (new KitchenOrderService)->markReady($kitchenOrder->id, $f['chef']->id);

    $this->actingAs($f['waiter']);
    $kitchenOrder->fresh()->update(['status' => 'cancelled', 'cancellation_reason' => 'Guest walked out']);

    expect(mrRice($f))->toBe(6.0);
    expect(IngredientTransaction::where('type', 'return')->exists())->toBeFalse();

    $waste = KitchenWasteLog::sole();
    expect($waste->order_id)->toBe($kitchenOrder->id);
    expect($waste->menu_item_id)->toBe($f['jollof']->id);
    expect($waste->item_name)->toBe('Jollof Rice');
    expect($waste->quantity)->toBe(2);
    expect((float) $waste->sale_value)->toBe(5000.0);
    expect($waste->order_status_before)->toBe('ready');
    expect($waste->reason)->toBe('Guest walked out');
    expect($waste->recorded_by)->toBe($f['waiter']->id);
});

it('does not deduct again at Mark Ready for an older order that already deducted at creation', function () {
    $f = mrFixture();
    ['kitchen' => $legacy] = mrPlaceDineIn($f, beerQty: 0);

    // What every pre-Phase-0D dine-in ticket looks like: deducted when it
    // was ordered, stock_deducted_at stamped (the 2026-09-05 backfill did
    // the same for older history from the transaction ledger).
    InventoryService::deductInventoryForOrderItems($legacy->fresh());
    expect(mrRice($f))->toBe(8.0);

    (new KitchenOrderService)->markReady($legacy->id, $f['chef']->id);

    expect(mrRice($f))->toBe(8.0);
    expect(mrUsageRows($legacy))->toBe(1);
});

it('restocks an older, creation-deducted order voided before Mark Ready, as before', function () {
    $f = mrFixture();
    ['kitchen' => $legacy] = mrPlaceDineIn($f, beerQty: 0);
    InventoryService::deductInventoryForOrderItems($legacy->fresh());
    expect(mrRice($f))->toBe(8.0);

    $legacy->fresh()->update(['status' => 'cancelled', 'cancellation_reason' => 'Wrong table']);

    expect(mrRice($f))->toBe(10.0);
    expect(IngredientTransaction::where('reference', "order:{$legacy->id}")->where('type', 'return')->count())->toBe(1);
    expect(KitchenWasteLog::count())->toBe(0);
    expect($legacy->fresh()->stock_deducted_at)->toBeNull();
});

it('keeps room food deducting at Mark Ready, exactly once', function () {
    $f = mrFixture();
    $room = Room::create(['number' => '701', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Room Food Guest', 'guest_phone' => '0805'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    (new BookingService)->checkIn($booking, $receptionist->id);

    $orders = (new RoomOrderService)->placeOrder($room->id, ['menu_'.$f['jollof']->id => ['name' => 'Jollof Rice', 'price' => 2500, 'quantity' => 1]], $receptionist->id);
    $roomOrder = collect($orders)->firstWhere('destination', 'kitchen');

    expect(mrRice($f))->toBe(10.0);
    expect($roomOrder->fresh()->stock_deducted_at)->toBeNull();

    (new KitchenOrderService)->markReady($roomOrder->id, $f['chef']->id);
    expect(fn () => (new KitchenOrderService)->markReady($roomOrder->id, $f['chef']->id))->toThrow(ModelNotFoundException::class);

    expect(mrRice($f))->toBe(8.0);
    expect(mrUsageRows($roomOrder))->toBe(1);
});

it('leaves drink deduction exactly where it was: at order creation', function () {
    $f = mrFixture();
    ['bar' => $barOrder] = mrPlaceDineIn($f, beerQty: 3);

    expect(mrBeer($f))->toBe(17.0);
    expect($barOrder->fresh()->stock_deducted_at)->not->toBeNull();
    expect(InventoryTransaction::where('reference', "order:{$barOrder->id}")->where('type', 'sale')->sum('quantity'))->toEqual(3);

    // And a bar shortage still refuses the sale: the creation-path
    // deduction (no shortfall allowance) throws, same as it always has.
    InventoryItem::where('product_id', $f['beer']->id)->update(['quantity' => 1]);
    $shortBar = Order::create(['order_number' => 'ORD-SHORT-'.uniqid(), 'table_id' => $f['table']->id, 'user_id' => $f['waiter']->id, 'status' => 'pending', 'destination' => 'bar', 'total_amount' => 4000]);
    $shortBar->items()->create(['product_id' => $f['beer']->id, 'product_name' => 'Star Beer', 'item_type' => 'product', 'quantity' => 5, 'unit_price' => 800, 'subtotal' => 4000]);

    expect(fn () => InventoryService::deductInventoryForOrderItems($shortBar))->toThrow(Exception::class, 'Out of Stock');
    expect(mrBeer($f))->toBe(1.0);
});

it('never refuses Mark Ready on a shortage: stock goes negative and the shortfall is logged', function () {
    $f = mrFixture(riceInKitchen: 1);
    expect(InventoryService::enforceIngredientStock())->toBeTrue();

    ['kitchen' => $kitchenOrder] = mrPlaceDineIn($f, beerQty: 0);
    (new KitchenOrderService)->markReady($kitchenOrder->id, $f['chef']->id);

    expect($kitchenOrder->fresh()->status)->toBe('ready');
    expect(mrRice($f))->toBe(-1.0);

    $log = Activity::where('log_name', 'inventory')->where('subject_id', $kitchenOrder->id)->sole();
    expect($log->description)->toBe('Kitchen stock went short at Mark Ready');
    expect($log->causer_id)->toBe($f['chef']->id);
    expect($log->properties['shortfalls'][0])->toMatchArray(['item' => 'Jollof Rice', 'stock' => 'Rice', 'available' => 1.0, 'required' => 2.0]);
});

it('never refuses Mark Ready for a food Product that ran out either', function () {
    $f = mrFixture();
    $plantain = Product::create(['name' => 'Plantain Chips', 'price' => 500, 'category_id' => Category::where('type', 'food')->value('id'), 'is_active' => true]);
    // Never stocked in the kitchen at all.

    $orders = (new OrderSplitter)->handle([(string) $plantain->id => ['name' => 'Plantain Chips', 'price' => 500, 'quantity' => 2]], $f['table']->id, $f['waiter']->id, ['status' => 'pending', 'shift_id' => $f['waiterShift']->id]);
    $kitchenOrder = collect($orders)->sole();
    expect($kitchenOrder->destination)->toBe('kitchen');

    (new KitchenOrderService)->markReady($kitchenOrder->id, $f['chef']->id);

    expect((float) InventoryItem::where('product_id', $plantain->id)->where('warehouse_id', $f['kitchen']->id)->value('quantity'))->toBe(-2.0);
    expect(InventoryTransaction::where('reference', "order:{$kitchenOrder->id}")->where('type', 'sale')->count())->toBe(1);
});

it('still deducts a kitchen order created already paid (takeaway), since it never reaches Mark Ready', function () {
    $f = mrFixture();

    $orders = (new OrderSplitter)->handle(['menu_'.$f['jollof']->id => ['name' => 'Jollof Rice', 'price' => 2500, 'quantity' => 1]], null, $f['waiter']->id, [
        'status' => 'paid', 'amount_paid' => 2500, 'shift_id' => $f['waiterShift']->id,
    ]);

    expect(mrRice($f))->toBe(8.0);
    expect(collect($orders)->sole()->fresh()->stock_deducted_at)->not->toBeNull();
});

it('lets Mark Ready go negative with the kitchen-ingredient enforcement toggle off too', function () {
    Company::create(['id' => 1, 'name' => 'Test Co', 'enforce_kitchen_ingredient_stock' => false]);
    $f = mrFixture(riceInKitchen: 1);

    ['kitchen' => $kitchenOrder] = mrPlaceDineIn($f, beerQty: 0);
    (new KitchenOrderService)->markReady($kitchenOrder->id, $f['chef']->id);

    expect(mrRice($f))->toBe(-1.0);
    expect($kitchenOrder->fresh()->status)->toBe('ready');
});
