<?php

use App\Models\Product;
use App\Models\Category;
use App\Models\WareHouse;
use App\Models\InventoryItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('allows storekeeper to create, send and allows recipient to receive transfer updating inventory', function () {
    // create roles
    Role::findOrCreate('storekeeper');
    Role::findOrCreate('bartender');

    // create users
    $store = User::factory()->create();
    $store->assignRole('storekeeper');

    $bartender = User::factory()->create();
    $bartender->assignRole('bartender');

    // create warehouses
    WareHouse::create(['id' => 3, 'name' => 'Main', 'location' => 'Main', 'is_active' => 1]);
    WareHouse::create(['id' => 4, 'name' => 'Bar', 'location' => 'Bar', 'is_active' => 1]);

    // create product and seed main warehouse
    $cat = Category::create(['name' => 'Beverages', 'type' => 'drink']);
    $product = Product::create(['name' => 'Soda', 'price' => 200, 'category_id' => $cat->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => 3, 'quantity' => 50]);

    $payload = [
        'from_warehouse_id' => 3,
        'to_warehouse_id' => 4,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 5]
        ]
    ];

    // create transfer
    $this->actingAs($store)
        ->postJson('/stock-transfers', $payload)
        ->assertStatus(200)
        ->assertJsonFragment(['status' => 'pending']);

    $transferId = \App\Models\StockTransfer::latest()->value('id');

    // send transfer
    $this->actingAs($store)
        ->postJson("/stock-transfers/{$transferId}/send")
        ->assertStatus(200)
        ->assertJsonFragment(['status' => 'sent']);

    // receive as bartender
    $this->actingAs($bartender)
        ->postJson("/stock-transfers/{$transferId}/receive")
        ->assertStatus(200)
        ->assertJsonFragment(['status' => 'received']);

    // inventory should be moved
    $mainQty = \DB::table('inventory_items')->where('product_id', $product->id)->where('warehouse_id', 3)->value('quantity');
    $barQty = \DB::table('inventory_items')->where('product_id', $product->id)->where('warehouse_id', 4)->value('quantity');

    expect($mainQty)->toBe(45);
    expect($barQty)->toBe(5);
});
