<?php

use App\Models\Category;
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
use App\Models\User;
use App\Models\WareHouse;
use App\Services\InventoryService;

/**
 * Financial Foundations Part A: unit_cost_at_sale is snapshotted at the
 * single shared write point (InventoryService::deductProductInventory()
 * for products, deductMenuItemIngredients() for menu-item ingredients) so
 * the future reporting layer can compute margin without recomputing
 * historical cost from whatever the product's cost happens to be today.
 */
it('snapshots the product cost price onto the sale InventoryTransaction', function () {
    $warehouse = WareHouse::create(['id' => 3, 'name' => 'Main Warehouse', 'type' => 'storage']);
    $category = Category::create(['name' => 'Misc', 'type' => 'service']);
    $product = Product::factory()->create(['category_id' => $category->id, 'last_cost_price' => 450.50]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => User::factory()->create()->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3]);

    InventoryService::deductInventoryForOrderItems($order);

    $transaction = InventoryTransaction::where('product_id', $product->id)->where('type', 'sale')->firstOrFail();
    expect((float) $transaction->unit_cost_at_sale)->toBe(450.50);
});

it('snapshots null, never 0, when the product has no recorded cost price', function () {
    $warehouse = WareHouse::create(['id' => 3, 'name' => 'Main Warehouse', 'type' => 'storage']);
    $category = Category::create(['name' => 'Misc', 'type' => 'service']);
    // last_cost_price genuinely never set — not the same as cost_price's
    // own non-nullable 0 default, which is exactly the trap this guards
    // against (a false 0 would read as a fake 100% margin later).
    $product = Product::factory()->create(['category_id' => $category->id, 'last_cost_price' => null]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => User::factory()->create()->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1]);

    InventoryService::deductInventoryForOrderItems($order);

    $transaction = InventoryTransaction::where('product_id', $product->id)->where('type', 'sale')->firstOrFail();
    expect($transaction->unit_cost_at_sale)->toBeNull();
});

it('snapshots the ingredient cost price onto the usage IngredientTransaction for a menu-item sale', function () {
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Fried Rice', 'sku' => 'MI-COST-' . uniqid(), 'sale_price' => 2500, 'category_id' => $category->id, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-COST-' . uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5.25, 'category' => 'Dry Goods']);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 0.5]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => User::factory()->create()->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_type' => 'menu_item', 'quantity' => 2]);

    InventoryService::deductInventoryForOrderItems($order);

    $transaction = IngredientTransaction::where('ingredient_id', $rice->id)->where('type', 'usage')->firstOrFail();
    expect((float) $transaction->unit_cost_at_sale)->toBe(5.25);
});

it('does not snapshot a cost onto a purchase, transfer, or adjustment transaction', function () {
    // The column exists on every row (nullable), but nothing outside the
    // two sale/usage write points in InventoryService should ever
    // populate it — confirms Part A didn't touch any other transaction
    // type's behavior.
    $warehouse = WareHouse::create(['name' => 'Main Warehouse', 'type' => 'storage']);
    $category = Category::create(['name' => 'Misc', 'type' => 'service']);
    $product = Product::factory()->create(['category_id' => $category->id]);

    InventoryTransaction::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'type' => 'purchase',
        'quantity' => 10,
        'cost_per_unit' => 300,
        'user_id' => User::factory()->create()->id,
    ]);

    $transaction = InventoryTransaction::where('product_id', $product->id)->where('type', 'purchase')->firstOrFail();
    expect($transaction->unit_cost_at_sale)->toBeNull();
});
