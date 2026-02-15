<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\InventoryService;
use App\Services\DashboardCache;

class OrderObserver
{
    /**
     * Handle the Order "creating" event.
     */
    public function created(Order $order): void
    {
        DashboardCache::clearForOrder($order);
    }

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

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        DashboardCache::clearForOrder($order);
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        DashboardCache::clearForOrder($order);
    }
}