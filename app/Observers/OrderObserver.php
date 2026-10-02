<?php

namespace App\Observers;

use App\Models\Commission;
use App\Models\KitchenWasteLog;
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
        // Return inventory when an order is cancelled or returned. These are
        // the only two terminal statuses that give stock back — every other
        // status transition leaves inventory untouched.
        $restockStatuses = ['cancelled', 'returned'];

        if (
            $order->isDirty('status')
            && in_array($order->status, $restockStatuses, true)
            && ! in_array($order->getOriginal('status'), $restockStatuses, true)
        ) {
            // Cooked food is never restocked (Phase 0D): a kitchen ticket
            // cancelled once the cook has marked it Ready is recorded as
            // waste instead, with no stock movement at all. A kitchen
            // ticket cancelled before that still goes through the normal
            // return path, whose stock_deducted_at guard gives back only
            // what really left the shelf (nothing for anything created
            // since Phase 0D; everything for an older one that deducted at
            // creation). Return tickets are untouched by this.
            if ($this->isCookedKitchenCancellation($order)) {
                $this->recordKitchenWaste($order);
            } elseif ($this->isReturnTicketThatNeverRestocks($order)) {
                // Phase 0F. A REJECTED return ticket (status cancelled) means
                // the item never came back — nothing to restock. And a
                // kitchen return never restocks either: the dish was cooked
                // (ReturnConfirmationService records it as waste) or not made
                // yet (nothing was ever deducted). Only a confirmed BAR
                // return puts stock back.
            } else {
                InventoryService::returnInventoryForCancelledOrder($order);
            }
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

    /**
     * Anything past pending/preparing has left the pass — ready, served,
     * or already paid/partial (takeaway). 'preparing' is never actually set
     * today, but it is still a not-yet-cooked state if it ever is.
     */
    private function isCookedKitchenCancellation(Order $order): bool
    {
        return $order->status === 'cancelled'
            && $order->destination === 'kitchen'
            && ! $order->is_return
            && ! in_array($order->getOriginal('status'), ['pending', 'preparing'], true);
    }

    private function isReturnTicketThatNeverRestocks(Order $order): bool
    {
        return $order->is_return
            && ($order->status === 'cancelled' || $order->destination === 'kitchen');
    }

    private function recordKitchenWaste(Order $order): void
    {
        foreach ($order->items as $item) {
            KitchenWasteLog::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'item_type' => $item->item_type,
                'product_id' => $item->product_id,
                'menu_item_id' => $item->menu_item_id,
                'item_name' => $item->product_name,
                'quantity' => $item->quantity,
                'sale_value' => round((float) $item->unit_price * $item->quantity, 2),
                'order_status_before' => $order->getOriginal('status'),
                'reason' => $order->cancellation_reason,
                'recorded_by' => auth()->id(),
            ]);
        }
    }

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
