<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

class ServedConfirmationService
{
    /**
     * Waiter (or a supervisor) confirms an order has been picked up/carried
     * to the table. This is the only path that moves an order from 'ready'
     * to 'served' — payment (both the full modal and the fast Mark Paid
     * path) is blocked until this happens.
     *
     * @throws \Exception
     */
    public function confirm(Order $order, User $user): void
    {
        $isOwner = $order->user_id === $user->id;
        $isSupervisor = $user->hasRole(['manager', 'admin', 'super_admin']);

        if (!$isOwner && !$isSupervisor) {
            throw new \Exception('Only the waiter who took this order (or a supervisor) can confirm it as served.');
        }

        if ($order->status !== 'ready') {
            throw new \Exception('This order must be marked ready by the kitchen/bar before it can be confirmed served.');
        }

        $order->update([
            'status' => 'served',
            'served_at' => now(),
        ]);
    }
}
