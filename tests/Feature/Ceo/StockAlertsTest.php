<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\WareHouse;
use App\Services\Ceo\StockAlertService;

it('flags a product at or below its threshold as Low, and at zero as Sold Out', function () {
    $warehouse = WareHouse::create(['name' => 'Bar Alerts', 'type' => 'consumer', 'is_active' => true]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $lowProduct = Product::create(['name' => 'Low Beer', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $lowProduct->id, 'warehouse_id' => $warehouse->id, 'quantity' => StockAlertService::PRODUCT_LOW_THRESHOLD]);

    $soldOutProduct = Product::create(['name' => 'Empty Beer', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $soldOutProduct->id, 'warehouse_id' => $warehouse->id, 'quantity' => 0]);

    $healthyProduct = Product::create(['name' => 'Full Beer', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $healthyProduct->id, 'warehouse_id' => $warehouse->id, 'quantity' => StockAlertService::PRODUCT_LOW_THRESHOLD + 1]);

    $alerts = (new StockAlertService())->alerts();

    expect($alerts->firstWhere('name', 'Low Beer')['state'])->toBe('low');
    expect($alerts->firstWhere('name', 'Empty Beer')['state'])->toBe('sold_out');
    expect($alerts->firstWhere('name', 'Full Beer'))->toBeNull();
});

it('includes ingredients across every warehouse, not just the kitchen-linked ones', function () {
    $mainStore = WareHouse::create(['name' => 'Main Store Alerts', 'type' => 'storage', 'is_active' => true]);

    $ingredient = Ingredient::create([
        'name' => 'Tomatoes', 'sku' => 'ING-' . uniqid(), 'unit_name' => 'kg',
        'quantity' => 0, 'cost_per_unit' => 100, 'category' => 'Vegetables',
    ]);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $mainStore->id, 'quantity' => StockAlertService::INGREDIENT_LOW_THRESHOLD]);

    $alerts = (new StockAlertService())->alerts();
    $row = $alerts->firstWhere('name', 'Tomatoes');

    expect($row)->not->toBeNull();
    expect($row['item_type'])->toBe('ingredient');
    expect($row['state'])->toBe('low');
    expect($row['location'])->toBe('Main Store Alerts');
});

it('sorts sold-out items before low items', function () {
    $warehouse = WareHouse::create(['name' => 'Sort Test WH', 'type' => 'consumer', 'is_active' => true]);
    $category = Category::create(['name' => 'Drinks Sort', 'type' => 'drink']);

    $low = Product::create(['name' => 'Sort Low', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $low->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1]);

    $soldOut = Product::create(['name' => 'Sort Sold Out', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $soldOut->id, 'warehouse_id' => $warehouse->id, 'quantity' => 0]);

    $alerts = (new StockAlertService())->alerts()->values();

    expect($alerts->first()['state'])->toBe('sold_out');
});
