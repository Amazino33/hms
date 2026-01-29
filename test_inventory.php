<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Application;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Inventory Return Functionality\n";
echo "=====================================\n\n";

// Create a warehouse
$warehouse = Warehouse::factory()->create();
echo "Created warehouse: {$warehouse->name}\n";

// Create a product
$product = Product::factory()->create();
echo "Created product: {$product->name}\n";

// Create inventory for the product in the warehouse
$inventory = InventoryItem::create([
    'product_id' => $product->id,
    'warehouse_id' => $warehouse->id,
    'quantity' => 10,
]);
echo "Created inventory: 10 units\n";

// Create an order
$order = Order::factory()->create(['status' => 'pending']);
echo "Created order: {$order->order_number} (status: {$order->status})\n";

// Create an order item
$orderItem = OrderItem::factory()->create([
    'order_id' => $order->id,
    'product_id' => $product->id,
    'quantity' => 3,
]);
echo "Created order item: 3 units of {$product->name}\n";

// Deduct inventory (simulate order creation)
InventoryService::deductInventoryForOrderItems($order);
$inventory->refresh();
echo "After inventory deduction: {$inventory->quantity} units (expected: 7)\n";

// Now cancel the order (this should trigger the observer)
$order->update(['status' => 'cancelled']);
$inventory->refresh();
echo "After order cancellation: {$inventory->quantity} units (expected: 10)\n";

if ($inventory->quantity === 10) {
    echo "\n✅ SUCCESS: Inventory was properly returned when order was cancelled!\n";
} else {
    echo "\n❌ FAILURE: Inventory was not returned. Current quantity: {$inventory->quantity}\n";
}

// Clean up
$order->delete();
$inventory->delete();
$product->delete();
$warehouse->delete();

echo "\nTest completed.\n";