<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Ingredient;
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
            if ($orderItem->item_type === 'product') {
                self::returnProductInventory($orderItem);
            } elseif ($orderItem->item_type === 'menu_item') {
                self::returnMenuItemIngredients($orderItem);
            }
        }
    }

    /**
     * Return product inventory
     */
    private static function returnProductInventory($orderItem): void
    {
        $product = Product::with('category')->find($orderItem->product_id);

        if (!$product) {
            return; // Skip if product doesn't exist
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

    /**
     * Return menu item ingredients back to stock
     */
    private static function returnMenuItemIngredients($orderItem): void
    {
        $menuItem = \App\Models\MenuItem::with('recipes.ingredient')->find($orderItem->menu_item_id);

        if (!$menuItem) {
            return;
        }

        foreach ($menuItem->recipes as $recipe) {
            $requiredQuantity = $recipe->quantity_needed * $orderItem->quantity;
            $recipe->ingredient->increment('quantity', $requiredQuantity);
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
            if ($item->item_type === 'product') {
                self::deductProductInventory($item);
            } elseif ($item->item_type === 'menu_item') {
                self::deductMenuItemIngredients($item);
            }
        }
    }

    /**
     * Deduct product inventory
     */
    private static function deductProductInventory($item): void
    {
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

    /**
     * Deduct ingredients for menu item
     */
    private static function deductMenuItemIngredients($item): void
    {
        $menuItem = \App\Models\MenuItem::with('recipes.ingredient')->find($item->menu_item_id);

        if (!$menuItem) {
            throw new \Exception("Menu item not found: {$item->product_name}");
        }

        foreach ($menuItem->recipes as $recipe) {
            $requiredQuantity = $recipe->quantity_needed * $item->quantity;

            if ($recipe->ingredient->quantity < $requiredQuantity) {
                throw new \Exception("Insufficient ingredients: Only {$recipe->ingredient->quantity} {$recipe->ingredient->unit_name} of {$recipe->ingredient->name} available, need {$requiredQuantity}");
            }

            $recipe->ingredient->decrement('quantity', $requiredQuantity);
        }
    }

    /**
     * Check if ingredients are available for a menu item
     *
     * @param int $menuItemId
     * @param int $quantity
     * @return array Array of insufficient ingredients or empty if all available
     */
    public static function checkMenuItemIngredientsAvailability(int $menuItemId, int $quantity): array
    {
        $menuItem = \App\Models\MenuItem::with('recipes.ingredient')->find($menuItemId);
        $insufficient = [];

        if (!$menuItem) {
            return ['Menu item not found'];
        }

        foreach ($menuItem->recipes as $recipe) {
            $requiredQuantity = $recipe->quantity_needed * $quantity;

            if ($recipe->ingredient->quantity < $requiredQuantity) {
                $insufficient[] = [
                    'ingredient' => $recipe->ingredient->name,
                    'available' => $recipe->ingredient->quantity,
                    'required' => $requiredQuantity,
                    'unit' => $recipe->ingredient->unit_name,
                ];
            }
        }

        return $insufficient;
    }

    /**
     * Get low stock alerts for ingredients used in popular menu items
     *
     * @param int $threshold Threshold quantity for low stock alert
     * @return array
     */
    public static function getLowStockAlerts(int $threshold = 10): array
    {
        $lowStockIngredients = Ingredient::where('quantity', '<=', $threshold)
            ->with(['recipes.menuItem' => function($query) {
                $query->where('available_for_sale', true);
            }])
            ->get()
            ->filter(function($ingredient) {
                return $ingredient->recipes->isNotEmpty();
            });

        $alerts = [];

        foreach ($lowStockIngredients as $ingredient) {
            $menuItems = $ingredient->recipes->pluck('menuItem')->filter()->unique('id');

            $alerts[] = [
                'ingredient' => $ingredient,
                'affected_menu_items' => $menuItems,
            ];
        }

        return $alerts;
    }
}