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

function seedWarehouses(): array
{
    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    return [$main, $bar, $kitchen];
}

it('logs a sale transaction when a product order is created', function () {
    [$main, $bar] = seedWarehouses();
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => $user->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 4, 'item_type' => 'product']);

    InventoryService::deductInventoryForOrderItems($order->fresh(['items']));

    $stock = InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity');
    expect((int) $stock)->toBe(16);

    $txn = InventoryTransaction::where('product_id', $product->id)->where('type', 'sale')->latest('id')->first();
    expect($txn)->not->toBeNull();
    expect((int) $txn->quantity)->toBe(4);
    expect($txn->user_id)->toBe($user->id);
    expect($txn->reference)->toBe("order:{$order->id}");
});

it('logs a usage transaction against the kitchen warehouse when a menu item order is created', function () {
    [$main, $bar, $kitchen] = seedWarehouses();
    $user = User::factory()->create();

    $ingredient = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $menuItem = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-1', 'type' => 'food', 'sale_price' => 1000, 'available_for_sale' => true]);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $ingredient->id, 'quantity_needed' => 2]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => $user->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'product_id' => null, 'quantity' => 3, 'item_type' => 'menu_item']);

    InventoryService::deductInventoryForOrderItems($order->fresh(['items']));

    $stock = IngredientInventoryItem::where('ingredient_id', $ingredient->id)->where('warehouse_id', $kitchen->id)->value('quantity');
    expect((float) $stock)->toEqual(4.0); // 10 - (2 * 3)

    $txn = IngredientTransaction::where('ingredient_id', $ingredient->id)->where('type', 'usage')->latest('id')->first();
    expect($txn)->not->toBeNull();
    expect((float) $txn->quantity)->toEqual(6.0);
    expect($txn->warehouse_id)->toBe($kitchen->id);
});

it('restocks products and logs a return transaction when an order is cancelled', function () {
    [$main, $bar] = seedWarehouses();
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => $user->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 4, 'item_type' => 'product']);

    InventoryService::deductInventoryForOrderItems($order->fresh(['items']));
    $order->update(['status' => 'cancelled']);

    $stock = InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity');
    expect((int) $stock)->toBe(20);

    $txn = InventoryTransaction::where('product_id', $product->id)->where('type', 'return')->latest('id')->first();
    expect($txn)->not->toBeNull();
    expect((int) $txn->quantity)->toBe(4);
});

it('restocks products and ingredients when a kitchen/bar order is marked returned (not just cancelled)', function () {
    [$main, $bar, $kitchen] = seedWarehouses();
    $user = User::factory()->create();

    $ingredient = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE-2', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $menuItem = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-2', 'type' => 'food', 'sale_price' => 1000, 'available_for_sale' => true]);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $ingredient->id, 'quantity_needed' => 2]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => $user->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'product_id' => null, 'quantity' => 3, 'item_type' => 'menu_item']);

    InventoryService::deductInventoryForOrderItems($order->fresh(['items']));
    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->value('quantity'))->toEqual(4.0);

    // Transition straight to 'returned' (this is the BarDisplay/KitchenDisplay path) —
    // previously this only restocked products, and only via ad-hoc code with no logging.
    $order->update(['status' => 'returned']);

    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->value('quantity'))->toEqual(10.0);

    $txn = IngredientTransaction::where('ingredient_id', $ingredient->id)->where('type', 'return')->latest('id')->first();
    expect($txn)->not->toBeNull();
});
