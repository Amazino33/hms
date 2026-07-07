<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\WareHouse;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Cache;

it('reports no shortfall when every recipe ingredient has enough stock', function () {
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Fried Rice', 'sku' => 'MI-FR-' . uniqid(), 'sale_price' => 2500, 'category_id' => $category->id, 'available_for_sale' => true]);

    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE-INV', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    $oil = Ingredient::create(['name' => 'Oil', 'sku' => 'ING-OIL-INV', 'unit_name' => 'litre', 'quantity' => 0, 'cost_per_unit' => 3, 'category' => 'Dry Goods']);

    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 0.5]);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $oil->id, 'quantity_needed' => 0.1]);

    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);
    IngredientInventoryItem::create(['ingredient_id' => $oil->id, 'warehouse_id' => $kitchen->id, 'quantity' => 5]);

    $shortfalls = InventoryService::checkMenuItemIngredientsAvailability($menuItem->id, 2);

    expect($shortfalls)->toBeEmpty();
});

it('reports every ingredient that falls short, not just the first one, in a single batched query', function () {
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Fried Rice', 'sku' => 'MI-FR-' . uniqid(), 'sale_price' => 2500, 'category_id' => $category->id, 'available_for_sale' => true]);

    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE-INV2', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    $oil = Ingredient::create(['name' => 'Oil', 'sku' => 'ING-OIL-INV2', 'unit_name' => 'litre', 'quantity' => 0, 'cost_per_unit' => 3, 'category' => 'Dry Goods']);

    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 5]);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $oil->id, 'quantity_needed' => 5]);

    // Only enough for 1 portion of each, but 3 are being ordered.
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 5]);
    IngredientInventoryItem::create(['ingredient_id' => $oil->id, 'warehouse_id' => $kitchen->id, 'quantity' => 5]);

    $shortfalls = InventoryService::checkMenuItemIngredientsAvailability($menuItem->id, 3);

    expect($shortfalls)->toHaveCount(2);
    expect(collect($shortfalls)->pluck('ingredient')->all())->toEqual(['Rice', 'Oil']);
});

it('treats an ingredient with no inventory row at the warehouse as zero stock, not a query error', function () {
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $menuItem = MenuItem::create(['name' => 'Fried Rice', 'sku' => 'MI-FR-' . uniqid(), 'sale_price' => 2500, 'category_id' => $category->id, 'available_for_sale' => true]);

    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE-INV3', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 1]);
    // Deliberately no IngredientInventoryItem row created for this ingredient.

    $shortfalls = InventoryService::checkMenuItemIngredientsAvailability($menuItem->id, 1);

    expect($shortfalls)->toHaveCount(1);
    expect($shortfalls[0]['available'])->toEqual(0.0);
});

it('caches the bar warehouse id lookup and busts the cache when any warehouse is saved', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);

    expect(InventoryService::getBarWarehouseId())->toBe($bar->id);
    expect(Cache::has('inventory_service:bar_warehouse_id'))->toBeTrue();

    // Saving ANY warehouse busts the cache, so the next call recomputes
    // rather than serving a stale id if a warehouse's type/existence changed.
    $bar->touch();

    expect(Cache::has('inventory_service:bar_warehouse_id'))->toBeFalse();
});
