<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\InventoryService;
use Livewire\Livewire;

it('sorts in-stock products above out-of-stock ones on the Sales Page', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    $bar = WareHouse::firstOrCreate(['id' => InventoryService::getBarWarehouseId()], ['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    // Created deliberately out-of-stock first, so a naive "DB insertion
    // order" listing would show it above the in-stock one below —
    // proving the sort actually reorders rather than happening to match.
    $outOfStock = Product::create(['name' => 'Zero Stock Drink', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $outOfStock->id, 'warehouse_id' => $bar->id, 'quantity' => 0]);

    $inStock = Product::create(['name' => 'In Stock Drink', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $inStock->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $component = Livewire::actingAs($waiter)
        ->test('pos')
        ->call('loadProducts')
        ->set('activeCategoryId', $category->id);

    $products = collect($component->viewData('products'));
    $names = $products->pluck('name')->values()->all();

    expect(array_search('In Stock Drink', $names, true))
        ->toBeLessThan(array_search('Zero Stock Drink', $names, true));
});

it('treats a menu item with no recipe (unlimited/service item) as in-stock, not sunk to the bottom', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Food', 'type' => 'food']);

    $unlimited = \App\Models\MenuItem::create([
        'name' => 'Service Charge', 'sku' => 'SC1', 'sale_price' => 100, 'category_id' => $category->id, 'available_for_sale' => true,
    ]);

    $ingredient = \App\Models\Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 100, 'category' => 'Grains']);
    $kitchen = WareHouse::firstOrCreate(['id' => InventoryService::getKitchenWarehouseId()], ['name' => 'Kitchen', 'type' => 'consumer']);
    \App\Models\IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $kitchen->id, 'quantity' => 0]);

    $outOfStock = \App\Models\MenuItem::create([
        'name' => 'Rice Dish', 'sku' => 'RD1', 'sale_price' => 800, 'category_id' => $category->id, 'available_for_sale' => true,
    ]);
    \App\Models\Recipe::create(['menu_item_id' => $outOfStock->id, 'ingredient_id' => $ingredient->id, 'quantity_needed' => 1]);

    $component = Livewire::actingAs($waiter)
        ->test('pos')
        ->call('loadProducts')
        ->set('activeCategoryId', $category->id);

    $menuItems = collect($component->viewData('menuItems'));
    $names = $menuItems->pluck('name')->values()->all();

    expect(array_search('Service Charge', $names, true))
        ->toBeLessThan(array_search('Rice Dish', $names, true));
});
