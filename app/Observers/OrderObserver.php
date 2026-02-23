<?php

namespace App\Observers;

use App\Models\Commission;
use App\Models\Order;
use App\Services\DashboardCache;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        DashboardCache::clearForOrder($order);
        // Commission for newly-created paid orders is handled by OrderSplitter
        // AFTER all OrderItems are inserted. We cannot do it here because items
        // do not exist yet when the 'created' event fires.
    }

    /**
     * Handle the Order "updating" event.
     * Called before the model is saved when updating.
     */
    public function updating(Order $order): void
    {
        // Return inventory when an order is cancelled.
        if ($order->isDirty('status') && $order->status === 'cancelled' && $order->getOriginal('status') !== 'cancelled') {
            InventoryService::returnInventoryForCancelledOrder($order);
        }
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        DashboardCache::clearForOrder($order);

        // ── Commission Engine ────────────────────────────────────────────────────
        // Trigger only when status transitions to 'paid' for the first time.
        // Skip if: no status change, already had a commission, or no waiter assigned.
        if (
            ! $order->wasChanged('status')           // status didn't change this save
            || $order->status !== 'paid'              // didn't become paid
            || $order->commission()->exists()         // commission already recorded
            || ! $order->user_id                      // no waiter on this order
        ) {
            return;
        }

        $this->calculateAndSaveCommission($order);
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        DashboardCache::clearForOrder($order);
    }

    // ── Private Helpers ──────────────────────────────────────────────────────────

    private function calculateAndSaveCommission(Order $order): void
    {
        try {
            // Eager-load items → product/menuItem → category in a single query.
            $order->loadMissing(['items.product.category', 'items.menuItem.category']);

            $total = 0.0;

            foreach ($order->items as $item) {
                $rate = 0.0;

                if ($item->item_type === 'product' && $item->product && $item->product->category) {
                    $rate = (float) $item->product->category->commission_rate;
                } elseif ($item->item_type === 'menu_item' && $item->menuItem && $item->menuItem->category) {
                    $rate = (float) $item->menuItem->category->commission_rate;
                }

                $total += $item->quantity * $rate;
            }

            // Only create a record if there is something to credit.
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
