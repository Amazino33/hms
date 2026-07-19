<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\WareHouse;

it('creates a zero-quantity Main Store row for an active product only stocked elsewhere', function () {
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $barOnly = Product::create(['name' => 'Bar-only Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $barOnly->id, 'warehouse_id' => $bar->id, 'quantity' => 12]);

    $this->artisan('app:backfill-main-store-inventory', ['--force' => true])
        ->assertExitCode(0);

    $mainStoreRow = InventoryItem::where('product_id', $barOnly->id)->where('warehouse_id', $mainStore->id)->first();
    expect($mainStoreRow)->not->toBeNull();
    expect((int) $mainStoreRow->quantity)->toBe(0);

    // Bar's own quantity is untouched.
    expect((int) InventoryItem::where('product_id', $barOnly->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(12);
});

it('does not touch a product that already has a Main Store row', function () {
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $existing = InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $mainStore->id, 'quantity' => 40]);

    $this->artisan('app:backfill-main-store-inventory', ['--force' => true])
        ->assertExitCode(0);

    expect((int) $existing->fresh()->quantity)->toBe(40);
    expect(InventoryItem::where('product_id', $product->id)->count())->toBe(1);
});

it('skips inactive products', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $discontinued = Product::create(['name' => 'Discontinued Drink', 'price' => 500, 'category_id' => $category->id, 'is_active' => false]);
    InventoryItem::create(['product_id' => $discontinued->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);

    $this->artisan('app:backfill-main-store-inventory', ['--force' => true])
        ->assertExitCode(0);

    expect(InventoryItem::where('product_id', $discontinued->id)->count())->toBe(1);
});

it('does nothing without --force when the confirmation is declined', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $barOnly = Product::create(['name' => 'Bar-only Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $barOnly->id, 'warehouse_id' => $bar->id, 'quantity' => 12]);

    $this->artisan('app:backfill-main-store-inventory')
        ->expectsConfirmation('Proceed?', 'no')
        ->assertExitCode(0);

    expect(InventoryItem::where('product_id', $barOnly->id)->count())->toBe(1);
});
