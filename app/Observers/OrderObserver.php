<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\InventoryService;

class OrderObserver
{
    /**
     * Handle the Order "updating" event.
     * This is called before the model is saved when updating.
     */
    public function updating(Order $order): void
    {
        // Check if status is being changed to 'cancelled'
        if ($order->isDirty('status') && $order->status === 'cancelled' && $order->getOriginal('status') !== 'cancelled') {
            // Return inventory items back to stock
            InventoryService::returnInventoryForCancelledOrder($order);
        }
    }
}