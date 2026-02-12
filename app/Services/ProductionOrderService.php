<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\OrderItem;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

class ProductionOrderService
{
    /**
     * Create production orders for menu items in an order
     *
     * @param \App\Models\Order $order
     * @return void
     */
    public static function createProductionOrdersForOrder($order): void
    {
        foreach ($order->items as $orderItem) {
            if ($orderItem->item_type === 'menu_item' && $orderItem->menu_item_id) {
                self::createProductionOrder($orderItem);
            }
        }
    }

    /**
     * Create a production order for a specific order item
     *
     * @param OrderItem $orderItem
     * @return ProductionOrder
     */
    public static function createProductionOrder(OrderItem $orderItem): ProductionOrder
    {
        return ProductionOrder::create([
            'order_item_id' => $orderItem->id,
            'menu_item_id' => $orderItem->menu_item_id, // Use menu_item_id for menu items
            'menu_item_name' => $orderItem->product_name, // Use product_name for menu items
            'quantity' => $orderItem->quantity,
            'status' => 'pending',
            'priority' => self::determinePriority($orderItem),
        ]);
    }

    /**
     * Determine priority based on menu item type or other factors
     *
     * @param OrderItem $orderItem
     * @return string
     */
    private static function determinePriority(OrderItem $orderItem): string
    {
        // For now, use normal priority. Could be enhanced based on menu item type, time of day, etc.
        return 'normal';
    }

    /**
     * Start production of an order
     *
     * @param ProductionOrder $productionOrder
     * @param int $userId
     * @return void
     */
    public static function startProduction(ProductionOrder $productionOrder, int $userId): void
    {
        $productionOrder->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'assigned_to_user_id' => $userId,
        ]);
    }

    /**
     * Complete production of an order
     *
     * @param ProductionOrder $productionOrder
     * @return void
     */
    public static function completeProduction(ProductionOrder $productionOrder): void
    {
        $productionOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Cancel a production order
     *
     * @param ProductionOrder $productionOrder
     * @return void
     */
    public static function cancelProduction(ProductionOrder $productionOrder): void
    {
        $productionOrder->update([
            'status' => 'cancelled',
        ]);
    }

    /**
     * Get pending production orders for kitchen display
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPendingProductionOrders()
    {
        return ProductionOrder::with(['orderItem.order', 'menuItem'])
            ->pending()
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get production orders by status
     *
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getProductionOrdersByStatus(string $status)
    {
        return ProductionOrder::with(['orderItem.order.table', 'menuItem', 'assignedUser'])
            ->where('status', $status)
            ->orderBy('created_at', 'asc')
            ->get();
    }
}