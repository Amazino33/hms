<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use App\Events\OrderCreated;
use App\Services\InventoryService;
use App\Services\ProductionOrderService;
use Filament\Actions\Action;

class OrderSplitter
{
    /**
     * Create separate orders per destination based on cart.
     *
     * @param array|\Illuminate\Support\Collection $cart  keyed by productId => [name,price,quantity] or menuItemId prefixed with 'menu_' => [name,price,quantity]
     * @param int $tableId
     * @param int $userId
     * @param array $options optional keys: payment_method, amount_paid, guest_id
     * @return array created Order models
     */
    public function handle($cart, int $tableId, int $userId, array $options = []) : array
    {
        $created = [];

        DB::transaction(function () use ($cart, $tableId, $userId, $options, &$created) {
            // Normalize cart so each item includes its id and type
            $prepared = collect($cart)->map(function ($item, $key) {
                $item['key'] = $key;
                if (str_starts_with($key, 'menu_')) {
                    $item['type'] = 'menu_item';
                    $item['menu_item_id'] = (int) str_replace('menu_', '', $key);
                } else {
                    $item['type'] = 'product';
                    $item['product_id'] = (int) $key;
                }
                return $item;
            });

            $groups = $prepared->groupBy(function ($item) {
                if ($item['type'] === 'menu_item') {
                    // Menu items always go to kitchen
                    return 'kitchen';
                } else {
                    $product = Product::with('category')->find($item['product_id']);
                    
                    // Determine warehouse dynamically based on product category
                    $warehouseId = self::getWarehouseForProduct($product);
                    
                    return $warehouseId === self::getBarWarehouseId() ? 'bar' : 
                           ($warehouseId === self::getKitchenWarehouseId() ? 'kitchen' : 'main');
                }
            });

            // Calculate total cart amount for proportional payment distribution
            $totalCartAmount = 0;
            foreach ($groups as $destination => $items) {
                $totalCartAmount += collect($items)->sum(fn($i) => $i['price'] * $i['quantity']);
            }

            $paidAmount = $options['amount_paid'] ?? 0;
            $orderStatus = $options['status'] ?? 'pending';

            foreach ($groups as $destination => $items) {
                $groupTotal = collect($items)->sum(fn($i) => $i['price'] * $i['quantity']);

                // Distribute payment proportionally for partial payments
                if ($orderStatus === 'paid') {
                    $amountPaid = $groupTotal;
                } elseif ($orderStatus === 'partial' && $totalCartAmount > 0) {
                    $amountPaid = round(($groupTotal / $totalCartAmount) * $paidAmount, 2);
                } else {
                    $amountPaid = $paidAmount; // For pending or other cases
                }

                $order = Order::create([
                    'order_number' => 'ORD-' . time() . '-' . strtoupper(substr($destination,0,1)),
                    'total_amount' => $groupTotal,
                    'amount_paid' => $amountPaid,
                    'status' => $orderStatus,
                    'payment_method' => $options['payment_method'] ?? 'cash',
                    'table_id' => $tableId,
                    'user_id' => $userId,
                    'guest_id' => $options['guest_id'] ?? null,
                    'destination' => $destination,
                ]);

                foreach ($items as $item) {
                    if ($item['type'] === 'menu_item') {
                        $menuItem = MenuItem::find($item['menu_item_id']);
                        if (!$menuItem) {
                            throw new \Exception("Menu item not found: {$item['name']}");
                        }
                        OrderItem::create([
                            'order_id' => $order->id,
                            'menu_item_id' => $item['menu_item_id'], // Use menu_item_id for menu items
                            'product_id' => null, // No product_id for menu items
                            'product_name' => $item['name'], // Use product_name for menu items
                            'item_type' => 'menu_item',
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['price'],
                            'subtotal' => $item['price'] * $item['quantity'],
                        ]);
                    } else {
                        $product = Product::with('category')->find($item['product_id']);
                        $warehouseId = match(true) {
                            $product && $product->category && $product->category->type === 'drink' => 4,
                            $product && $product->category && $product->category->type === 'food' => 5,
                            default => 3,
                        };

                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $item['product_id'],
                            'menu_item_id' => null, // No menu_item_id for products
                            'product_name' => $item['name'],
                            'item_type' => 'product',
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['price'],
                            'subtotal' => $item['price'] * $item['quantity'],
                        ]);
                    }
                }

                // Deduct inventory for all items in this order
                InventoryService::deductInventoryForOrderItems($order);

                // Create production orders for menu items
                ProductionOrderService::createProductionOrdersForOrder($order);

                event(new OrderCreated($order));

                // Send database notification to all staff users
                $staffUsers = \App\Models\User::whereHas('roles', function($q) {
                    $q->whereIn('name', ['super_admin', 'chef', 'waiter']);
                })->get();

                foreach ($staffUsers as $staffUser) {
                    \Filament\Notifications\Notification::make()
                        ->title("New Order: {$order->order_number}")
                        ->body("New {$order->destination} order for table {$order->table->name}")
                        ->info()
                        ->actions([
                            Action::make('view')
                                ->button()
                                ->url(\App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $order->id])),
                        ])
                        ->sendToDatabase($staffUser);
                }

                $created[] = $order;
            }
        });

        return $created;
    }

    /**
     * Get the appropriate warehouse ID for a product based on its category
     */
    private static function getWarehouseForProduct($product): int
    {
        return match(true) {
            $product && $product->category && $product->category->type === 'drink' => self::getBarWarehouseId(),
            $product && $product->category && $product->category->type === 'food' => self::getKitchenWarehouseId(),
            default => 3, // Default storage warehouse
        };
    }

    /**
     * Get the bar warehouse ID (first consumer warehouse)
     */
    private static function getBarWarehouseId(): int
    {
        return \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->first()?->id ?? 4;
    }

    /**
     * Get the kitchen warehouse ID (second consumer warehouse)
     */
    private static function getKitchenWarehouseId(): int
    {
        $consumerWarehouses = \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->get();
        if ($consumerWarehouses->count() > 1) {
            return $consumerWarehouses[1]->id; // Second consumer warehouse
        }
        return $consumerWarehouses->first()?->id ?? 5;
    }
}
