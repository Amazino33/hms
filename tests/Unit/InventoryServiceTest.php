<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\WareHouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns inventory when order is cancelled', function () {
    // Create a warehouse with the ID that InventoryService expects for storage (id=3)
    $warehouse = \App\Models\Warehouse::create([
        'id' => 3,
        'name' => 'Main Warehouse',
        'type' => 'storage',
    ]);

    // Create a category mapped to storage so the service picks the storage warehouse
    $category = \App\Models\Category::create([
        'name' => 'Misc',
        'type' => 'service',
    ]);

    // Create a product tied to that category
    $product = Product::factory()->create(['category_id' => $category->id]);

    // Create inventory for the product in the warehouse (id=3)
    InventoryItem::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 10,
    ]);

    // Create an order
    $order = Order::factory()->create(['status' => 'pending']);

    // Create an order item
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    // Deduct inventory (simulate order creation)
    InventoryService::deductInventoryForOrderItems($order);

    // Check that inventory was deducted
    $inventory = InventoryItem::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->first();
    expect($inventory->quantity)->toBe(7);

    // Now cancel the order
    $order->update(['status' => 'cancelled']);

    // Check that inventory was returned
    $inventory->refresh();
    expect($inventory->quantity)->toBe(10);
});

it('does not return inventory for non-cancelled status changes', function () {
    // Create a warehouse with the ID that InventoryService expects for storage (id=3)
    $warehouse = \App\Models\Warehouse::create([
        'id' => 3,
        'name' => 'Main Warehouse',
        'type' => 'storage',
    ]);

    // Create a category mapped to storage so the service picks the storage warehouse
    $category = \App\Models\Category::create([
        'name' => 'Misc',
        'type' => 'service',
    ]);

    // Create a product tied to that category
    $product = Product::factory()->create(['category_id' => $category->id]);

    // Create inventory for the product in the warehouse (id=3)
    InventoryItem::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 10,
    ]);

    // Create an order
    $order = Order::factory()->create(['status' => 'pending']);

    // Create an order item
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    // Deduct inventory (simulate order creation)
    InventoryService::deductInventoryForOrderItems($order);

    // Check that inventory was deducted
    $inventory = InventoryItem::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->first();
    expect($inventory->quantity)->toBe(7);

    // Change status to 'ready' (not cancelled)
    $order->update(['status' => 'ready']);

    // Check that inventory was NOT returned
    $inventory->refresh();
    expect($inventory->quantity)->toBe(7);
});