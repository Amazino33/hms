<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Bar Mark Ready, extracted unchanged from BarDisplay::markAsReady()
 * (Phase 5, owner decision 2026-10-02) — the same move KitchenOrderService
 * made for the kitchen. The Bar Display page and a guest room drink's
 * Release (D24) call this one copy, so a room order's stock leaves the bar
 * in exactly one place.
 *
 * Only the database part lives here; the page keeps its cache clearing and
 * "Ready!" notifications.
 */
class BarOrderService
{
    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException if the
     *                                                              order isn't a pending, bar-destination order
     */
    public function markReady(int $orderId, ?int $actorUserId): Order
    {
        // Scoped to pending+bar, not a bare findOrFail — calling this on an
        // already-ready/served/paid order (or a KITCHEN-destination one)
        // would silently flip its status back. Row-locked inside a
        // transaction because a room order's stock deduction happens right
        // here — two concurrent calls must not deduct twice.
        return DB::transaction(function () use ($orderId, $actorUserId) {
            $order = Order::with(['items.product', 'table', 'booking.room'])
                ->where('status', 'pending')
                ->where('destination', 'bar')
                ->lockForUpdate()
                ->findOrFail($orderId);

            $order->update([
                'status' => 'ready',
                'processed_by_user_id' => $actorUserId,
            ]);

            // Every other destination already deducted stock at order
            // creation (OrderSplitter::handle()); a room order deferred it
            // until now, this exact transition.
            if ($order->booking_id) {
                InventoryService::deductInventoryForOrderItems($order);
            }

            return $order;
        });
    }
}
