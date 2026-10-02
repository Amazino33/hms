<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Guest\RoomDeliveryService;

/**
 * Phase 5: when the kitchen marks a ROOM order Ready, its guest request
 * lines start waiting for a porter. Listens to the Order model's own
 * update — KitchenOrderService::markReady() and OrderObserver are left
 * exactly as they are. (Drinks are handled by the bar Release itself, D24.)
 */
class GuestRoomOrderObserver
{
    public function updated(Order $order): void
    {
        if ($order->booking_id
            && $order->destination === 'kitchen'
            && $order->wasChanged('status')
            && $order->status === 'ready') {
            (new RoomDeliveryService)->readyForDispatch($order);
        }
    }
}
