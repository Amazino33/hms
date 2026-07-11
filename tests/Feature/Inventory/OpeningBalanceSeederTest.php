<?php

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Database\Seeders\OpeningBalanceSeeder;

it('seeds products and opening-balance transactions and stays idempotent on a second run', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    User::factory()->create()->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    (new OpeningBalanceSeeder())->run();
    $productsAfterFirst = Product::count();
    $transactionsAfterFirst = InventoryTransaction::where('type', 'opening_balance')->count();

    expect($productsAfterFirst)->toBeGreaterThan(0);
    expect($transactionsAfterFirst)->toBeGreaterThan(0);

    (new OpeningBalanceSeeder())->run();

    expect(Product::count())->toBe($productsAfterFirst);
    expect(InventoryTransaction::where('type', 'opening_balance')->count())->toBe($transactionsAfterFirst);
});

it('matches an existing product case-insensitively instead of creating a duplicate', function () {
    $store = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    User::factory()->create()->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    $category = \App\Models\Category::create(['name' => 'Drinks', 'type' => 'drink']);
    Product::create(['name' => 'star beer', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);

    (new OpeningBalanceSeeder())->run();

    expect(Product::whereRaw('LOWER(name) = ?', ['star beer'])->count())->toBe(1);
});

it('skips creating an opening-balance transaction for a zero-stock row', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    User::factory()->create()->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    (new OpeningBalanceSeeder())->run();

    $baileys = Product::where('name', 'Baileys')->first();
    expect($baileys)->not->toBeNull();
    expect(InventoryTransaction::where('product_id', $baileys->id)->where('type', 'opening_balance')->exists())->toBeFalse();
});
