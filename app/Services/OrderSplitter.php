<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Events\OrderCreated;
use Filament\Actions\Action;

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
}
