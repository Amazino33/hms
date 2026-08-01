<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\InventoryService;

/**
 * companies.enforce_kitchen_ingredient_stock: while kitchen ingredient
 * stock is still being set up, a food sale must keep recording (for
 * accounting) even when the ingredients behind it aren't really there yet.
 */
it('still blocks an insufficient-ingredient sale when enforcement is on (default)', function () {
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-ENF-'.uniqid(), 'sale_price' => 2000, 'category_id' => $category->id, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-ENF-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 1]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 0]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => User::factory()->create()->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_type' => 'menu_item', 'quantity' => 1]);

    expect(fn () => InventoryService::deductInventoryForOrderItems($order))->toThrow(Exception::class, 'Insufficient ingredients');
});

it('lets an insufficient-ingredient sale through and goes negative once enforcement is turned off', function () {
    Company::create(['id' => 1, 'name' => 'Test Co', 'enforce_kitchen_ingredient_stock' => false]);

    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-ENF-'.uniqid(), 'sale_price' => 2000, 'category_id' => $category->id, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-ENF-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 3]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 1]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => User::factory()->create()->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_type' => 'menu_item', 'quantity' => 1]);

    InventoryService::deductInventoryForOrderItems($order);

    expect((float) IngredientInventoryItem::where('ingredient_id', $rice->id)->where('warehouse_id', $kitchen->id)->value('quantity'))->toBe(-2.0);
    expect(IngredientTransaction::where('ingredient_id', $rice->id)->where('type', 'usage')->where('reference', "order:{$order->id}")->exists())->toBeTrue();
});

it('creates the missing kitchen inventory row rather than fatal-erroring when the ingredient was never stocked there and enforcement is off', function () {
    Company::create(['id' => 1, 'name' => 'Test Co', 'enforce_kitchen_ingredient_stock' => false]);

    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Suya', 'sku' => 'MI-ENF-'.uniqid(), 'sale_price' => 1500, 'category_id' => $category->id, 'available_for_sale' => true]);
    // Deliberately never stocked at Kitchen at all — no IngredientInventoryItem row exists yet.
    $beef = Ingredient::create(['name' => 'Beef', 'sku' => 'ING-ENF-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 8, 'category' => 'Meat']);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $beef->id, 'quantity_needed' => 0.5]);

    $order = Order::factory()->create(['status' => 'pending', 'user_id' => User::factory()->create()->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_type' => 'menu_item', 'quantity' => 1]);

    InventoryService::deductInventoryForOrderItems($order);

    expect((float) IngredientInventoryItem::where('ingredient_id', $beef->id)->where('warehouse_id', $kitchen->id)->value('quantity'))->toBe(-0.5);
});

it('reads the toggle off Company::find(1), defaulting to enforced when no company row exists', function () {
    expect(InventoryService::enforceIngredientStock())->toBeTrue();

    Company::create(['id' => 1, 'name' => 'Test Co', 'enforce_kitchen_ingredient_stock' => false]);
    expect(InventoryService::enforceIngredientStock())->toBeFalse();
});
