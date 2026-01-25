<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Events\OrderCreated;

class OrderSplitter
{
    /**
     * Create separate orders per destination based on cart.
     *
     * @param array|\Illuminate\Support\Collection $cart  keyed by productId => [name,price,quantity]
     * @param int $tableId
     * @param int $userId
     * @param array $options optional keys: payment_method, amount_paid, guest_id
     * @return array created Order models
     */
    public function handle($cart, int $tableId, int $userId, array $options = []) : array
    {
        $created = [];

        DB::transaction(function () use ($cart, $tableId, $userId, $options, &$created) {
            // Normalize cart so each item includes its product_id (groupBy resets keys)
            $prepared = collect($cart)->map(function ($item, $productId) {
                $item['product_id'] = $productId;
                return $item;
            });

            $groups = $prepared->groupBy(function ($item) {
                $product = Product::with('category')->find($item['product_id']);
                $warehouseId = match(true) {
                    $product && $product->category && $product->category->type === 'drink' => 4,
                    $product && $product->category && $product->category->type === 'food' => 5,
                    default => 3,
                };
                return $warehouseId === 4 ? 'bar' : ($warehouseId === 5 ? 'kitchen' : 'main');
            });

            foreach ($groups as $destination => $items) {
                $groupTotal = collect($items)->sum(fn($i) => $i['price'] * $i['quantity']);

                $order = Order::create([
                    'order_number' => 'ORD-' . time() . '-' . strtoupper(substr($destination,0,1)),
                    'total_amount' => $groupTotal,
                    'amount_paid' => $options['amount_paid'] ?? 0,
                    'status' => $options['status'] ?? 'pending',
                    'payment_method' => $options['payment_method'] ?? 'cash',
                    'table_id' => $tableId,
                    'user_id' => $userId,
                    'guest_id' => $options['guest_id'] ?? null,
                    'destination' => $destination,
                ]);

                foreach ($items as $item) {
                    $productId = $item['product_id'];
                    $product = Product::with('category')->find($productId);
                    $warehouseId = match(true) {
                        $product && $product->category && $product->category->type === 'drink' => 4,
                        $product && $product->category && $product->category->type === 'food' => 5,
                        default => 3,
                    };

                    // Check stock
                    $currentStock = DB::table('inventory_items')
                        ->where('product_id', $productId)
                        ->where('warehouse_id', $warehouseId)
                        ->value('quantity');

                    if (($currentStock ?? 0) < $item['quantity']) {
                        throw new \Exception("Out of Stock: Only {$currentStock} left of {$item['name']}");
                    }
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'product_name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['price'],
                        'subtotal' => $item['price'] * $item['quantity'],
                    ]);

                    DB::table('inventory_items')
                        ->where('product_id', $productId)
                        ->where('warehouse_id', $warehouseId)
                        ->decrement('quantity', $item['quantity']);
                }

                event(new OrderCreated($order));
                $created[] = $order;
            }
        });

        return $created;
    }
}
