<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Return inventory items back to stock when an order is cancelled or returned.
     *
     * @param Order $order
     * @return void
     */
    public static function returnInventoryForCancelledOrder(Order $order): void
    {
        foreach ($order->items as $orderItem) {
            if ($orderItem->item_type === 'product') {
                self::returnProductInventory($orderItem, $order);
            } elseif ($orderItem->item_type === 'menu_item') {
                self::returnMenuItemIngredients($orderItem, $order);
            }
        }
    }

    /**
     * Return product inventory, logging the movement as an InventoryTransaction.
     */
    private static function returnProductInventory($orderItem, Order $order): void
    {
        $product = Product::with('category')->find($orderItem->product_id);

        if (!$product) {
            return; // Skip if product doesn't exist
        }

        $warehouseId = self::getWarehouseForProduct($product);

        DB::transaction(function () use ($orderItem, $product, $warehouseId, $order) {
            $inventory = InventoryItem::query()
                ->where('product_id', $orderItem->product_id)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($inventory) {
                $inventory->increment('quantity', $orderItem->quantity);
            } else {
                InventoryItem::create([
                    'product_id' => $orderItem->product_id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $orderItem->quantity,
                ]);
            }

            InventoryTransaction::create([
                'product_id' => $orderItem->product_id,
                'warehouse_id' => $warehouseId,
                'type' => 'return',
                'quantity' => $orderItem->quantity,
                'reference' => "order:{$order->id}",
                'user_id' => $order->user_id ?? auth()->id(),
            ]);
        });
    }

    /**
     * Return menu item ingredients back to stock (kitchen warehouse), logging
     * the movement as an IngredientTransaction.
     */
    private static function returnMenuItemIngredients($orderItem, Order $order): void
    {
        $menuItem = \App\Models\MenuItem::with('recipes.ingredient')->find($orderItem->menu_item_id);

        if (!$menuItem) {
            return;
        }

        $warehouseId = self::getKitchenWarehouseId();

        foreach ($menuItem->recipes as $recipe) {
            $requiredQuantity = $recipe->quantity_needed * $orderItem->quantity;

            DB::transaction(function () use ($recipe, $requiredQuantity, $warehouseId, $order) {
                $inventory = IngredientInventoryItem::query()
                    ->where('ingredient_id', $recipe->ingredient_id)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    $inventory->increment('quantity', $requiredQuantity);
                } else {
                    IngredientInventoryItem::create([
                        'ingredient_id' => $recipe->ingredient_id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $requiredQuantity,
                    ]);
                }

                IngredientTransaction::create([
                    'ingredient_id' => $recipe->ingredient_id,
                    'warehouse_id' => $warehouseId,
                    'type' => 'return',
                    'quantity' => $requiredQuantity,
                    'reference' => "order:{$order->id}",
                    'user_id' => $order->user_id ?? auth()->id(),
                ]);
            });
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
                self::deductProductInventory($item, $order);
            } elseif ($item->item_type === 'menu_item') {
                self::deductMenuItemIngredients($item, $order);
            }
        }
    }

    /**
     * Deduct product inventory, logging the movement as an InventoryTransaction.
     */
    private static function deductProductInventory($item, Order $order): void
    {
        $productId = $item->product_id;
        $product = Product::with('category')->find($productId);

        $warehouseId = self::getWarehouseForProduct($product);

        DB::transaction(function () use ($item, $productId, $warehouseId, $order) {
            $inventory = InventoryItem::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            $currentStock = $inventory->quantity ?? 0;

            if ($currentStock < $item->quantity) {
                throw new \Exception("Out of Stock: Only {$currentStock} left of {$item->product_name}");
            }

            $inventory->decrement('quantity', $item->quantity);

            InventoryTransaction::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'type' => 'sale',
                'quantity' => $item->quantity,
                'reference' => "order:{$order->id}",
                'user_id' => $order->user_id ?? auth()->id(),
            ]);
        });
    }

    /**
     * Deduct ingredients for menu item (kitchen warehouse), logging each
     * movement as an IngredientTransaction.
     */
    private static function deductMenuItemIngredients($item, Order $order): void
    {
        $menuItem = \App\Models\MenuItem::with('recipes.ingredient')->find($item->menu_item_id);

        if (!$menuItem) {
            throw new \Exception("Menu item not found: {$item->product_name}");
        }

        $warehouseId = self::getKitchenWarehouseId();

        foreach ($menuItem->recipes as $recipe) {
            $requiredQuantity = $recipe->quantity_needed * $item->quantity;

            DB::transaction(function () use ($recipe, $requiredQuantity, $warehouseId, $order, $item) {
                $inventory = IngredientInventoryItem::query()
                    ->where('ingredient_id', $recipe->ingredient_id)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                $currentStock = $inventory->quantity ?? 0;

                if ($currentStock < $requiredQuantity) {
                    throw new \Exception("Insufficient ingredients: Only {$currentStock} {$recipe->ingredient->unit_name} of {$recipe->ingredient->name} available, need {$requiredQuantity}");
                }

                $inventory->decrement('quantity', $requiredQuantity);

                IngredientTransaction::create([
                    'ingredient_id' => $recipe->ingredient_id,
                    'warehouse_id' => $warehouseId,
                    'type' => 'usage',
                    'quantity' => $requiredQuantity,
                    'reference' => "order:{$order->id}",
                    'user_id' => $order->user_id ?? auth()->id(),
                ]);
            });
        }
    }

    /**
     * Check if ingredients are available for a menu item (against kitchen
     * warehouse stock, since that is where consumption happens)
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

        $warehouseId = self::getKitchenWarehouseId();

        // One query for every recipe ingredient's stock instead of one
        // query per ingredient — this runs on every single menu-item tap,
        // so an N+1 here is paid on every click, not just once.
        $ingredientIds = $menuItem->recipes->pluck('ingredient_id')->all();
        $stockByIngredientId = IngredientInventoryItem::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->pluck('quantity', 'ingredient_id');

        foreach ($menuItem->recipes as $recipe) {
            $requiredQuantity = $recipe->quantity_needed * $quantity;
            $available = (float) ($stockByIngredientId[$recipe->ingredient_id] ?? 0);

            if ($available < $requiredQuantity) {
                $insufficient[] = [
                    'ingredient' => $recipe->ingredient->name,
                    'available' => $available,
                    'required' => $requiredQuantity,
                    'unit' => $recipe->ingredient->unit_name,
                ];
            }
        }

        return $insufficient;
    }

    /**
     * Current kitchen-warehouse stock for a single ingredient.
     */
    public static function getIngredientStock(int $ingredientId, ?int $warehouseId = null): float
    {
        $warehouseId ??= self::getKitchenWarehouseId();

        return (float) (IngredientInventoryItem::query()
            ->where('ingredient_id', $ingredientId)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity') ?? 0);
    }

    /**
     * Get low stock alerts for ingredients used in popular menu items
     * (evaluated against kitchen-warehouse stock)
     *
     * @param int $threshold Threshold quantity for low stock alert
     * @return array
     */
    public static function getLowStockAlerts(int $threshold = 10): array
    {
        $warehouseId = self::getKitchenWarehouseId();

        $lowStockIngredientIds = IngredientInventoryItem::query()
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '<=', $threshold)
            ->pluck('ingredient_id');

        $lowStockIngredients = Ingredient::whereIn('id', $lowStockIngredientIds)
            ->with(['recipes.menuItem' => function($query) {
                $query->where('available_for_sale', true);
            }])
            ->get()
            ->filter(function($ingredient) {
                $recipes = $ingredient->recipes ?? collect();
                return $recipes->isNotEmpty();
            });

        $alerts = [];

        foreach ($lowStockIngredients as $ingredient) {
            $recipes = $ingredient->recipes ?? collect();
            $menuItems = $recipes->pluck('menuItem')->filter()->unique('id');

            $alerts[] = [
                'ingredient' => $ingredient,
                'affected_menu_items' => $menuItems,
            ];
        }

        return $alerts;
    }

    /**
     * Get the appropriate warehouse ID for a product based on its category
     */
    public static function getWarehouseForProduct($product): int
    {
        return match(true) {
            $product && $product->category && $product->category->type === 'drink' => self::getBarWarehouseId(),
            $product && $product->category && $product->category->type === 'food' => self::getKitchenWarehouseId(),
            default => 3, // Default storage warehouse
        };
    }

    /**
     * Get the bar warehouse ID (first consumer warehouse). Cached — this is
     * called once per product in the POS grid's render loop plus again on
     * every menu-item tap, and which warehouse is "the bar" essentially
     * never changes between requests.
     */
    public static function getBarWarehouseId(): int
    {
        return Cache::remember('inventory_service:bar_warehouse_id', 3600, function () {
            return \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->first()?->id ?? 4;
        });
    }

    /**
     * Get the kitchen warehouse ID (second consumer warehouse). Cached for
     * the same reason as getBarWarehouseId().
     */
    public static function getKitchenWarehouseId(): int
    {
        return Cache::remember('inventory_service:kitchen_warehouse_id', 3600, function () {
            $consumerWarehouses = \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->get();
            if ($consumerWarehouses->count() > 1) {
                return $consumerWarehouses[1]->id; // Second consumer warehouse
            }
            return $consumerWarehouses->first()?->id ?? 5;
        });
    }
}
