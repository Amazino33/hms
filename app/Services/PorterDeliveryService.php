<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Custody tracking for room orders once the kitchen/bar marks them Ready:
 * a porter picks it up (stamped who/when) and later confirms delivery to
 * the room (status -> served, mirroring ServedConfirmationService's
 * waiter-confirms-served pattern, but keyed off picked_up_by instead of
 * the order's own user_id — for a room order that's the receptionist who
 * placed it, not the porter carrying it).
 */
class PorterDeliveryService
{
    public function pickUp(Order $order, User $porter): Order
    {
        return DB::transaction(function () use ($order, $porter) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (! $order->booking_id) {
                throw new \Exception('Only room orders go through porter delivery.');
            }

            if ($order->status !== 'ready') {
                throw new \Exception('This order is not ready for pickup.');
            }

            if ($order->picked_up_at) {
                throw new \Exception('This order has already been picked up.');
            }

            $order->update([
                'picked_up_by' => $porter->id,
                'picked_up_at' => now(),
            ]);

            activity('order')
                ->performedOn($order)
                ->causedBy($porter)
                ->log('Porter picked up room order for delivery');

            return $order->fresh();
        });
    }

    public function confirmDelivered(Order $order, User $porter): Order
    {
        return DB::transaction(function () use ($order, $porter) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (! $order->booking_id) {
                throw new \Exception('Only room orders go through porter delivery.');
            }

            if (! $order->picked_up_at) {
                throw new \Exception('This order must be picked up before delivery can be confirmed.');
            }

            if ($order->status === 'served') {
                throw new \Exception('This order has already been marked delivered.');
            }

            $isOwner = $order->picked_up_by === $porter->id;
            $isSupervisor = $porter->hasRole(['manager', 'admin', 'super_admin']);

            if (! $isOwner && ! $isSupervisor) {
                throw new \Exception('Only the porter who picked this up (or a supervisor) can confirm delivery.');
            }

            $order->update([
                'status' => 'served',
                'served_at' => now(),
            ]);

            activity('order')
                ->performedOn($order)
                ->causedBy($porter)
                ->log('Room order delivered to guest');

            return $order->fresh();
        });
    }
}
