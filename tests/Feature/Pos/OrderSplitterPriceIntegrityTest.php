<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\OrderSplitter;
use Illuminate\Support\Facades\DB;

/**
 * A cart item's 'price' and 'quantity' arrive as plain Livewire method
 * arguments, not a checksummed public property — anything sent there in
 * the request body must be treated as attacker-controlled (browser
 * devtools, a replayed/edited request, etc.). OrderSplitter must always
 * re-price from the product/menu item's own record and never trust the
 * client, regardless of what any caller (checkout, kiosk, phone) sends.
 */
function seedPriceIntegrityFixture(): array
{
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    DB::table('tables')->insert([
        ['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()],
    ]);

    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 20]);

    return [$waiter, $beer];
}

it('ignores a client-supplied product price and charges the real one instead', function () {
    [$waiter, $beer] = seedPriceIntegrityFixture();

    $cart = [
        $beer->id => ['name' => $beer->name, 'price' => 1, 'quantity' => 2], // real price is 500
    ];

    $orders = (new OrderSplitter())->handle($cart, 1, $waiter->id, []);

    expect($orders[0]->total_amount)->toEqual(1000.0); // 2 * real 500, not 2 * 1
    expect($orders[0]->items()->first()->unit_price)->toEqual(500.0);
});

it('clamps a client-supplied negative or zero quantity to at least 1, never inflating stock', function () {
    [$waiter, $beer] = seedPriceIntegrityFixture();

    $stockBefore = InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity');

    $cart = [
        $beer->id => ['name' => $beer->name, 'price' => 500, 'quantity' => -5],
    ];

    $orders = (new OrderSplitter())->handle($cart, 1, $waiter->id, []);

    // Clamped to 1, not -5 — negative quantity must never reach inventory
    // deduction (which would otherwise ADD stock instead of removing it).
    expect($orders[0]->items()->first()->quantity)->toBe(1);
    expect($orders[0]->total_amount)->toEqual(500.0);

    $stockAfter = InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity');
    expect((int) $stockAfter)->toBe((int) $stockBefore - 1);
});

it('ignores a client-supplied menu item price and charges the real sale_price instead', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-JR-' . uniqid(), 'sale_price' => 2500, 'category_id' => $category->id, 'available_for_sale' => true]);

    DB::table('tables')->insert([
        ['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $cart = [
        'menu_' . $menuItem->id => ['name' => $menuItem->name, 'price' => 1, 'quantity' => 1], // real price is 2500
    ];

    $orders = (new OrderSplitter())->handle($cart, 1, $waiter->id, []);

    expect($orders[0]->total_amount)->toEqual(2500.0);
    expect($orders[0]->items()->first()->unit_price)->toEqual(2500.0);
});
