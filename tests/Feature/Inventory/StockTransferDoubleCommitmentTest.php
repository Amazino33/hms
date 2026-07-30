<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;

/**
 * Production incident: a storekeeper created two separate transfers for
 * the same product out of the same warehouse, each for 2 units, while
 * Main Store only ever had 2 in total. Both creations succeeded because
 * createTransfer() only checked the warehouse's raw quantity at that
 * instant — neither transfer had actually debited anything yet, since
 * stock only moves at receipt. Whichever got received first silently
 * used up the real stock, leaving the second one stuck failing at
 * receive time with no way to have known in advance.
 */
it('refuses to create a second transfer for a product already fully committed to another pending transfer', function () {
    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'William Lawson', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 2]);

    $storekeeper = User::factory()->create();
    $service = new StockTransferService();

    // First transfer claims both units — still pending, nothing received.
    $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);

    // Warehouse quantity is still 2 (creation never debits) — but a second
    // transfer for the same 2 units should now be refused, since the first
    // one already has first claim on all of it.
    expect(fn () => $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]))->toThrow(
        Exception::class,
        'Not enough William Lawson available — only 0 left after 2 already committed to other pending transfers out of this warehouse.'
    );
});

it('allows a second transfer for the remaining, uncommitted portion of stock', function () {
    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 10]);

    $storekeeper = User::factory()->create();
    $service = new StockTransferService();

    $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 6],
    ]);

    // 4 left uncommitted — a transfer for exactly that much should succeed.
    $second = $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 4],
    ]);

    expect($second)->not->toBeNull();

    expect(fn () => $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->toThrow(Exception::class);
});

it('no longer counts a transfer as committed once it has been cancelled', function () {
    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Guinness', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 2]);

    $storekeeper = User::factory()->create();
    $service = new StockTransferService();

    $first = $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);

    expect(fn () => $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]))->toThrow(Exception::class);

    $service->cancelTransfer($first, 'Created by mistake', $storekeeper->id);

    // Now that the first transfer is cancelled, its claim on the stock is
    // released and a fresh transfer for the same amount should succeed.
    $second = $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);

    expect($second)->not->toBeNull();
});

it('applies the same already-committed check to ingredient transfers', function () {
    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $ingredient = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 2, 'category' => 'Grains']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $main->id, 'quantity' => 5]);

    $storekeeper = User::factory()->create();
    $service = new StockTransferService();

    $service->createTransfer($main->id, $kitchen->id, $storekeeper->id, [], [
        ['ingredient_id' => $ingredient->id, 'quantity' => 5],
    ]);

    expect(fn () => $service->createTransfer($main->id, $kitchen->id, $storekeeper->id, [], [
        ['ingredient_id' => $ingredient->id, 'quantity' => 1],
    ]))->toThrow(
        Exception::class,
        'Not enough Rice available — only 0 left after 5 already committed to other pending transfers out of this warehouse.'
    );
});
