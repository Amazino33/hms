<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class FloorPlanController extends Controller
{
    // Get order details for modal
    public function getOrderDetails($orderId)
    {
        $order = Order::with(['table', 'items.product'])->find($orderId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Check if the current user created this order
        if ($order->user_id !== auth()->id()) {
            return response()->json(['error' => 'Access denied. You can only view orders you created.'], 403);
        }

        // Ensure all items are loaded with their products
        $items = $order->items()->with('product')->get();

        return response()->json([
            'order' => $order,
            'items' => $items->map(function ($item) {
                return [
                    'product_name' => $item->product_name,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                ];
            })
        ]);
    }

    // Get popular items for quick add
    public function getPopularItems()
    {
        // Get most ordered products from the last 30 days
        $popularItems = Product::select('products.*')
            ->join('order_items', 'products.id', '=', 'order_items.product_id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', now()->subDays(30))
            ->groupBy('products.id', 'products.name', 'products.price', 'products.created_at', 'products.updated_at')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->limit(10)
            ->get(['products.id', 'products.name', 'products.price']);

        return response()->json([
            'items' => $popularItems
        ]);
    }

    // Add item to existing order
    public function addItemToOrder(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1'
        ]);

        $order = Order::find($request->order_id);

        // Check if the current user created this order
        if ($order->user_id !== auth()->id()) {
            return response()->json(['error' => 'Access denied. You can only modify orders you created.'], 403);
        }

        $product = Product::find($request->product_id);

        // Check if item already exists in order
        $existingItem = $order->items()->where('product_id', $product->id)->first();

        if ($existingItem) {
            // Update quantity
            $existingItem->update([
                'quantity' => $existingItem->quantity + $request->quantity
            ]);
        } else {
            // Add new item
            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_price' => $product->price,
                'quantity' => $request->quantity,
            ]);
        }

        // Recalculate total
        $total = $order->items->sum(function ($item) {
            return $item->unit_price * $item->quantity;
        });
        $order->update(['total_amount' => $total]);

        return response()->json([
            'success' => true,
            'message' => 'Item added successfully'
        ]);
    }
}