<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Return inventory items back to stock when an order is cancelled
     *
     * @param Order $order
     * @return void
     */
    public static function returnInventoryForCancelledOrder(Order $order): void
    {
        foreach ($order->items as $orderItem) {
            $product = Product::with('category')->find($orderItem->product_id);

            if (!$product) {
                continue; // Skip if product doesn't exist
            }

            // Determine warehouse based on product category
            $warehouseId = match(true) {
                $product && $product->category && $product->category->type === 'drink' => 4,
                $product && $product->category && $product->category->type === 'food' => 5,
                default => 3,
            };

            // Return the quantity back to inventory
            DB::table('inventory_items')
                ->where('product_id', $orderItem->product_id)
                ->where('warehouse_id', $warehouseId)
                ->increment('quantity', $orderItem->quantity);
        }
    }

    /**
     * Deduct inventory items when an order is created
     * (This is the reverse of returnInventoryForCancelledOrder)
     *
     * @param Order $order
     * @return void
     * @throws \Exception
     */
    public static function deductInventoryForOrderItems(Order $order): void
    {
        foreach ($order->items as $item) {
            $productId = $item->product_id;
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

            if (($currentStock ?? 0) < $item->quantity) {
                throw new \Exception("Out of Stock: Only {$currentStock} left of {$item->product_name}");
            }

            // Deduct from inventory
            DB::table('inventory_items')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->decrement('quantity', $item->quantity);
        }
    }
}