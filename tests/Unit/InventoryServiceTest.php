<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\WareHouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns inventory when order is cancelled', function () {
    // Create a warehouse
    $warehouse = Warehouse::factory()->create();

    // Create a product
    $product = Product::factory()->create();

    // Create inventory for the product in the warehouse
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
    // Create a warehouse
    $warehouse = Warehouse::factory()->create();

    // Create a product
    $product = Product::factory()->create();

    // Create inventory for the product in the warehouse
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