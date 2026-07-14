<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\MenuItem;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use App\Events\OrderCreated;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Log;

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
    public function handle($cart, ?int $tableId, int $userId, array $options = []) : array
    {
        $created = [];

        DB::transaction(function () use ($cart, $tableId, $userId, $options, &$created) {
            // Normalize cart so each item includes its id and type. The
            // client-supplied 'price' and 'quantity' are NEVER trusted here
            // — 'price' is overwritten with the product/menu item's actual
            // current price, and 'quantity' is clamped to a positive
            // integer, before any total/inventory math ever sees them. A
            // Livewire method argument (unlike a public property) carries
            // no checksum, so anything sent as 'price' in the request body
            // must be treated as attacker-controlled.
            $prepared = collect($cart)->map(function ($item, $key) {
                $item['key'] = $key;
                if (str_starts_with($key, 'menu_')) {
                    $item['type'] = 'menu_item';
                    $item['menu_item_id'] = (int) str_replace('menu_', '', $key);

                    $menuItem = MenuItem::find($item['menu_item_id']);
                    if (!$menuItem) {
                        throw new \Exception("Menu item not found: {$item['name']}");
                    }
                    $item['price'] = (float) $menuItem->sale_price;
                } else {
                    $item['type'] = 'product';
                    $item['product_id'] = (int) $key;

                    $product = Product::find($item['product_id']);
                    if (!$product) {
                        throw new \Exception("Product not found: {$item['name']}");
                    }
                    $item['price'] = (float) $product->price;
                }

                $item['quantity'] = max(1, (int) ($item['quantity'] ?? 1));

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
                // Room orders are placed by a receptionist, not a waiter —
                // there is no "waiter shift" to require. The per-destination
                // bartender/chef checks still apply unchanged: someone
                // actually has to be on duty at the bar/kitchen to make it.
                self::assertShiftsActive($destination, $userId, skipWaiterShiftCheck: (bool) ($options['booking_id'] ?? false));

                $groupTotal = collect($items)->sum(fn($i) => $i['price'] * $i['quantity']);

                // Distribute payment proportionally for partial payments
                if ($orderStatus === 'paid') {
                    $amountPaid = $groupTotal;
                } elseif ($orderStatus === 'partial' && $totalCartAmount > 0) {
                    $amountPaid = round(($groupTotal / $totalCartAmount) * $paidAmount, 2);
                } else {
                    $amountPaid = $paidAmount; // For pending or other cases
                }

                // Calculate proportional split amounts
                $paidCash = 0;
                $paidPos = 0;
                if ($totalCartAmount > 0) {
                    $paidCash = round(($groupTotal / $totalCartAmount) * ($options['paid_cash'] ?? 0), 2);
                    $paidPos = round(($groupTotal / $totalCartAmount) * ($options['paid_pos'] ?? 0), 2);
                }

                $order = Order::create([
                    'order_number' => 'ORD-' . time() . '-' . strtoupper(substr($destination,0,1)),
                    'total_amount' => $groupTotal,
                    'amount_paid' => $amountPaid,
                    'paid_cash' => $paidCash,
                    'paid_pos' => $paidPos,
                    'status' => $orderStatus,
                    'payment_method' => $options['payment_method'] ?? 'cash',
                    'table_id' => $tableId,
                    'user_id' => $userId,
                    'shift_id' => $options['shift_id'] ?? null,
                    'processed_by_user_id' => $options['processed_by_user_id'] ?? null,
                    'guest_id' => $options['guest_id'] ?? null,
                    'destination' => $destination,
                    'kiosk_device_id' => $options['kiosk_device_id'] ?? null,
                    'booking_id' => $options['booking_id'] ?? null,
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

                // Room orders defer this to the moment the kitchen/bar
                // display marks the order Ready (see markAsReady() in
                // KitchenDisplay/BarDisplay) instead of deducting eagerly
                // here — a guest's room order can be a while away from
                // actually being made, and stock shouldn't leave the shelf
                // on paper before it physically does.
                if (empty($options['defer_stock_deduction'])) {
                    InventoryService::deductInventoryForOrderItems($order);
                }

                // ── Commission: now that all OrderItems exist, calculate ────────
                if ($orderStatus === 'paid' && $order->user_id) {
                    self::calculateCommission($order);
                }

                event(new OrderCreated($order));

                // Send database notification to all staff users
                $staffUsers = \App\Models\User::whereHas('roles', function($q) {
                    $q->whereIn('name', ['super_admin', 'chef', 'waiter', 'porter']);
                })->get();

                foreach ($staffUsers as $staffUser) {
                    \Filament\Notifications\Notification::make()
                        ->title("New Order: {$order->order_number}")
                        ->body("New {$order->destination} order" . ($order->table ? " for table {$order->table->name}" : ' (Take Away)'))
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
     * The single choke point for order-creation shift enforcement — every
     * caller (POS, future API, imports, etc.) goes through here. A waiter
     * must be on an active, non-stale shift to create any order; a bar
     * destination additionally requires an active bartender shift, and a
     * kitchen destination an active chef shift, so every sale is always
     * inside exactly one accountable person's shift.
     *
     * @throws \Exception
     */
    private static function assertShiftsActive(string $destination, int $waiterUserId, bool $skipWaiterShiftCheck = false): void
    {
        if (!$skipWaiterShiftCheck && !Shift::query()->where('user_id', $waiterUserId)->activeNonStale('waiter')->exists()) {
            throw new \Exception('You must start a shift before creating orders.');
        }

        if ($destination === 'bar' && !Shift::query()->activeNonStale('bartender')->exists()) {
            throw new \Exception('No active bartender session — a bar sale cannot be recorded without one.');
        }

        if ($destination === 'kitchen' && !Shift::query()->activeNonStale('chef')->exists()) {
            throw new \Exception('No active chef session — a kitchen sale cannot be recorded without one.');
        }
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

    /**
     * Calculate and persist commission for a paid order.
     * Must be called AFTER all OrderItems have been inserted.
     */
    private static function calculateCommission(Order $order): void
    {
        try {
            // Fresh load of items with product/menuItem → category so rates are available.
            $order->load(['items.product.category', 'items.menuItem.category']);

            $total = 0.0;

            foreach ($order->items as $item) {
                $category = null;

                if ($item->item_type === 'product' && $item->product && $item->product->category) {
                    $category = $item->product->category;
                } elseif ($item->item_type === 'menu_item' && $item->menuItem && $item->menuItem->category) {
                    $category = $item->menuItem->category;
                }

                if ($category) {
                    $total += $item->quantity * (float) $category->commission_rate;
                }
            }

            if ($total > 0) {
                Commission::create([
                    'user_id'  => $order->user_id,
                    'order_id' => $order->id,
                    'amount'   => $total,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let commission logic break the payment flow.
            Log::error('Commission calculation failed for order #' . $order->id . ': ' . $e->getMessage());
        }
    }
}
