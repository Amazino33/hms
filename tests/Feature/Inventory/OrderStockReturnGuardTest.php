<?php

use App\Models\Booking;
use App\Models\Category;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Room;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\InventoryService;

/**
 * Cancelling an order gives its stock back. That is only correct when the
 * stock actually left in the first place — and for a room order it may not
 * have, because RoomOrderService defers the deduction until the
 * kitchen/bar display marks the order Ready.
 *
 * Before stock_deducted_at existed, OrderObserver credited stock on any
 * transition into cancelled/returned regardless, so cancelling a pending
 * room order invented inventory. That inflation then read as a *shortage*
 * at the next handover count, because the physical shelf never held it.
 */
beforeEach(function () {
    Cache::flush();
    Company::create(['id' => 1, 'name' => 'Test Hotel', 'enforce_kitchen_ingredient_stock' => false]);

    $this->bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $this->kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $this->drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $this->food = Category::create(['name' => 'Mains', 'type' => 'food']);

    $this->waiter = User::factory()->create();
});

function kitchenStock(int $ingredientId, int $warehouseId): float
{
    return (float) IngredientInventoryItem::where('ingredient_id', $ingredientId)
        ->where('warehouse_id', $warehouseId)->value('quantity');
}

function barStock(int $productId, int $warehouseId): float
{
    return (float) InventoryItem::where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)->value('quantity');
}

function riceDish(int $kitchenWarehouseId, int $foodCategoryId, float $openingStock = 10.0): array
{
    $rice = Ingredient::create([
        'name' => 'Rice', 'sku' => 'ING-'.Str::random(6), 'unit_name' => 'kg',
        'quantity' => 0, 'cost_per_unit' => 500, 'category' => 'Grains',
    ]);
    IngredientInventoryItem::create([
        'ingredient_id' => $rice->id, 'warehouse_id' => $kitchenWarehouseId, 'quantity' => $openingStock,
    ]);

    $dish = MenuItem::create([
        'name' => 'Jollof Rice', 'sku' => 'JOL-'.Str::random(6), 'category_id' => $foodCategoryId,
        'sale_price' => 2500, 'available_for_sale' => true,
    ]);
    Recipe::create(['menu_item_id' => $dish->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 2.0]);

    return [$rice, $dish];
}

function roomOrderFor(User $waiter, MenuItem $dish): Order
{
    $room = Room::create(['number' => '10'.rand(1, 9), 'type' => 'Single', 'price_per_night' => 20000, 'status' => 'occupied']);
    $guest = Guest::create(['name' => 'Test Guest', 'phone' => '08000000000']);
    $booking = Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => now()->subDay()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'total_price' => 20000,
    ]);

    $order = Order::create([
        'order_number' => 'ROOM-'.Str::random(5), 'user_id' => $waiter->id, 'booking_id' => $booking->id,
        'destination' => 'kitchen', 'status' => 'pending', 'total_amount' => 2500, 'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'menu_item_id' => $dish->id, 'item_type' => 'menu_item',
        'product_name' => $dish->name, 'quantity' => 1, 'unit_price' => 2500, 'subtotal' => 2500,
    ]);

    return $order->fresh('items');
}

/** The bug. */
it('does not invent ingredient stock when a room order is cancelled before it was ever marked ready', function () {
    [$rice, $dish] = riceDish($this->kitchen->id, $this->food->id);
    $order = roomOrderFor($this->waiter, $dish);

    // Deduction was deferred — nothing has left the shelf.
    expect($order->stock_deducted_at)->toBeNull()
        ->and(kitchenStock($rice->id, $this->kitchen->id))->toBe(10.0);

    $order->update(['status' => 'cancelled']);

    expect(kitchenStock($rice->id, $this->kitchen->id))->toBe(10.0)
        ->and(IngredientTransaction::where('reference', "order:{$order->id}")->where('type', 'return')->count())->toBe(0);
});

it('does not invent product stock either when a never-deducted room order is cancelled', function () {
    $product = Product::create([
        'name' => 'Amstel Malt', 'sku' => 'AM-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $this->bar->id, 'quantity' => 20]);

    $room = Room::create(['number' => '202', 'type' => 'Single', 'price_per_night' => 20000, 'status' => 'occupied']);
    $guest = Guest::create(['name' => 'Guest', 'phone' => '08000000001']);
    $booking = Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => now()->subDay()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'total_price' => 20000,
    ]);

    $order = Order::create([
        'order_number' => 'ROOM-P1', 'user_id' => $this->waiter->id, 'booking_id' => $booking->id,
        'destination' => 'bar', 'status' => 'pending', 'total_amount' => 1500, 'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'item_type' => 'product',
        'product_name' => 'Amstel Malt', 'quantity' => 3, 'unit_price' => 1500, 'subtotal' => 4500,
    ]);

    $order->fresh()->update(['status' => 'cancelled']);

    expect(barStock($product->id, $this->bar->id))->toBe(20.0);
});

/** The behaviour that must not regress. */
it('still gives ingredients back when a genuinely deducted order is cancelled', function () {
    [$rice, $dish] = riceDish($this->kitchen->id, $this->food->id);
    $order = roomOrderFor($this->waiter, $dish);

    // Simulates the kitchen marking it Ready, which is where a room order
    // finally deducts.
    InventoryService::deductInventoryForOrderItems($order);

    expect(kitchenStock($rice->id, $this->kitchen->id))->toBe(8.0)
        ->and($order->fresh()->stock_deducted_at)->not->toBeNull();

    $order->fresh()->update(['status' => 'cancelled']);

    expect(kitchenStock($rice->id, $this->kitchen->id))->toBe(10.0)
        ->and(IngredientTransaction::where('reference', "order:{$order->id}")->where('type', 'return')->count())->toBe(1);
});

it('still gives products back when a deducted order is cancelled', function () {
    $product = Product::create([
        'name' => 'Star Lager', 'sku' => 'SL-1', 'category_id' => $this->drinks->id,
        'price' => 1200, 'cost_price' => 700,
    ]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $this->bar->id, 'quantity' => 20]);

    $order = Order::create([
        'order_number' => 'TBL-1', 'user_id' => $this->waiter->id,
        'destination' => 'bar', 'status' => 'pending', 'total_amount' => 2400, 'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'item_type' => 'product',
        'product_name' => 'Star Lager', 'quantity' => 2, 'unit_price' => 1200, 'subtotal' => 2400,
    ]);

    InventoryService::deductInventoryForOrderItems($order->fresh('items'));
    expect(barStock($product->id, $this->bar->id))->toBe(18.0);

    $order->fresh()->update(['status' => 'cancelled']);

    expect(barStock($product->id, $this->bar->id))->toBe(20.0);
});

it('clears the marker on return so a second cancellation cannot double-credit', function () {
    [$rice, $dish] = riceDish($this->kitchen->id, $this->food->id);
    $order = roomOrderFor($this->waiter, $dish);

    InventoryService::deductInventoryForOrderItems($order);
    $order->fresh()->update(['status' => 'cancelled']);

    expect(kitchenStock($rice->id, $this->kitchen->id))->toBe(10.0)
        ->and($order->fresh()->stock_deducted_at)->toBeNull();

    // Force a second pass through the restock path, which the observer's
    // own status guard would normally prevent.
    InventoryService::returnInventoryForCancelledOrder($order->fresh('items'));

    expect(kitchenStock($rice->id, $this->kitchen->id))->toBe(10.0);
});

it('treats a returned order the same as a cancelled one', function () {
    [$rice, $dish] = riceDish($this->kitchen->id, $this->food->id);
    $order = roomOrderFor($this->waiter, $dish);

    $order->update(['status' => 'returned']);

    expect(kitchenStock($rice->id, $this->kitchen->id))->toBe(10.0);
});

it('marks the order deducted even when its menu items have no recipe to deduct', function () {
    $dish = MenuItem::create([
        'name' => 'Corkage', 'sku' => 'SRV-1', 'category_id' => $this->food->id,
        'sale_price' => 1000, 'available_for_sale' => true,
    ]);

    $order = Order::create([
        'order_number' => 'TBL-2', 'user_id' => $this->waiter->id,
        'destination' => 'kitchen', 'status' => 'pending', 'total_amount' => 1000, 'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'menu_item_id' => $dish->id, 'item_type' => 'menu_item',
        'product_name' => 'Corkage', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000,
    ]);

    InventoryService::deductInventoryForOrderItems($order->fresh('items'));

    expect($order->fresh()->stock_deducted_at)->not->toBeNull();
});

/**
 * Without a correct backfill this fix breaks the opposite way: every order
 * that predates the column reads as never-deducted, so cancelling a real,
 * already-deducted order after deploy would silently refuse to give its
 * stock back. The backfill derives the answer from the transaction ledger
 * — the only record of what actually happened.
 */
it('backfills the marker from the transaction ledger for orders that predate the column', function () {
    $product = Product::create([
        'name' => 'Legend', 'sku' => 'LG-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);
    [$rice, $dish] = riceDish($this->kitchen->id, $this->food->id);

    $deductedProductOrder = Order::create(['order_number' => 'OLD-1', 'user_id' => $this->waiter->id, 'status' => 'paid', 'total_amount' => 1500, 'is_return' => false]);
    $deductedDishOrder = Order::create(['order_number' => 'OLD-2', 'user_id' => $this->waiter->id, 'status' => 'paid', 'total_amount' => 2500, 'is_return' => false]);
    $neverDeductedOrder = Order::create(['order_number' => 'OLD-3', 'user_id' => $this->waiter->id, 'status' => 'pending', 'total_amount' => 2500, 'is_return' => false]);

    InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $this->bar->id, 'type' => 'sale',
        'quantity' => 1, 'reference' => "order:{$deductedProductOrder->id}", 'user_id' => $this->waiter->id,
    ]);
    IngredientTransaction::create([
        'ingredient_id' => $rice->id, 'warehouse_id' => $this->kitchen->id, 'type' => 'usage',
        'quantity' => 2, 'reference' => "order:{$deductedDishOrder->id}", 'user_id' => $this->waiter->id,
    ]);

    // Put the schema back to how production looks the moment before the
    // migration runs, then run the real migration file against real data.
    Schema::table('orders', fn ($table) => $table->dropColumn('stock_deducted_at'));

    $migration = require database_path('migrations/2026_09_05_090000_add_stock_deducted_at_to_orders_table.php');
    $migration->up();

    expect($deductedProductOrder->fresh()->stock_deducted_at)->not->toBeNull()
        ->and($deductedDishOrder->fresh()->stock_deducted_at)->not->toBeNull()
        ->and($neverDeductedOrder->fresh()->stock_deducted_at)->toBeNull();
});

/**
 * A return ticket is its own Order (is_return = true), created directly by
 * the POS return flow rather than through OrderSplitter — so it never
 * deducts anything, and putting stock back is its whole purpose.
 * ReturnConfirmationService flips it to 'returned' precisely to trigger
 * the restock. Gating that on a deduction it was never meant to make would
 * silently disable returns.
 */
it('still restocks a confirmed return ticket, which by design never deducted anything', function () {
    $product = Product::create([
        'name' => 'Trophy', 'sku' => 'TR-1', 'category_id' => $this->drinks->id,
        'price' => 1000, 'cost_price' => 600,
    ]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $this->bar->id, 'quantity' => 20]);

    $returnTicket = Order::create([
        'order_number' => 'RET-1', 'user_id' => $this->waiter->id, 'destination' => 'bar',
        'status' => 'pending', 'total_amount' => 0, 'is_return' => true,
    ]);
    OrderItem::create([
        'order_id' => $returnTicket->id, 'product_id' => $product->id, 'item_type' => 'product',
        'product_name' => 'Trophy', 'quantity' => 2, 'unit_price' => 1000, 'subtotal' => 0,
    ]);

    expect($returnTicket->stock_deducted_at)->toBeNull();

    $returnTicket->fresh()->update(['status' => 'returned']);

    expect(barStock($product->id, $this->bar->id))->toBe(22.0);
});

it('restocks a return ticket carrying a menu item back into ingredients', function () {
    [$rice, $dish] = riceDish($this->kitchen->id, $this->food->id);

    $returnTicket = Order::create([
        'order_number' => 'RET-2', 'user_id' => $this->waiter->id, 'destination' => 'kitchen',
        'status' => 'pending', 'total_amount' => 0, 'is_return' => true,
    ]);
    OrderItem::create([
        'order_id' => $returnTicket->id, 'menu_item_id' => $dish->id, 'item_type' => 'menu_item',
        'product_name' => $dish->name, 'quantity' => 1, 'unit_price' => 2500, 'subtotal' => 0,
    ]);

    $returnTicket->fresh()->update(['status' => 'returned']);

    expect(kitchenStock($rice->id, $this->kitchen->id))->toBe(12.0);
});

/**
 * The guard must read the marker from the database, not from whatever the
 * caller happens to be holding. Deducting against a freshly-loaded copy of
 * the row leaves the caller's own instance stale, and trusting that stale
 * attribute would skip a restock that is genuinely owed — stock that
 * really left never comes back, and the shortfall lands on whoever is
 * holding the count.
 */
it('restocks correctly even when the cancelling caller holds a stale order instance', function () {
    $product = Product::create([
        'name' => 'Heineken', 'sku' => 'HK-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $this->bar->id, 'quantity' => 20]);

    $order = Order::create([
        'order_number' => 'TBL-STALE', 'user_id' => $this->waiter->id,
        'destination' => 'bar', 'status' => 'pending', 'total_amount' => 6000, 'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'item_type' => 'product',
        'product_name' => 'Heineken', 'quantity' => 4, 'unit_price' => 1500, 'subtotal' => 6000,
    ]);

    // Deduct against a separate instance, leaving $order stale — exactly
    // what a service does when it reloads the row to lock it.
    InventoryService::deductInventoryForOrderItems($order->fresh(['items']));

    expect(barStock($product->id, $this->bar->id))->toBe(16.0)
        ->and($order->stock_deducted_at)->toBeNull(); // stale in memory

    $order->update(['status' => 'cancelled']);

    expect(barStock($product->id, $this->bar->id))->toBe(20.0);
});

it('does not touch the order status or fire a restock while recording the deduction', function () {
    [$rice, $dish] = riceDish($this->kitchen->id, $this->food->id);
    $order = roomOrderFor($this->waiter, $dish);

    InventoryService::deductInventoryForOrderItems($order);

    expect($order->fresh()->status)->toBe('pending')
        ->and(IngredientTransaction::where('reference', "order:{$order->id}")->where('type', 'usage')->count())->toBe(1)
        ->and(IngredientTransaction::where('reference', "order:{$order->id}")->where('type', 'return')->count())->toBe(0);
});
