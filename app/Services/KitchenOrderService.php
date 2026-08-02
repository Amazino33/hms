<?php

namespace App\Services;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Extracted, unchanged, from Filament\Pages\KitchenDisplay::markAsReady() so
 * both that page and the standalone /kds board call the exact same code
 * rather than two copies drifting apart — order-level readiness only,
 * matching current reality (there is no per-item readiness anywhere in this
 * app). Deliberately kitchen-only: BarDisplay keeps its own separate
 * markAsReady() untouched.
 */
class KitchenOrderService
{
    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException if the
     *                                                              order isn't a pending, kitchen-destination order
     */
    public function markReady(int $orderId, int $actorUserId): Order
    {
        // Scoped to pending+kitchen, not a bare findOrFail — this is called
        // from more than one surface now, and without this guard, calling
        // markReady() on an already-ready/served/paid order (or a BAR-
        // destination one) would silently flip its status back and re-fire
        // the "Ready!" notification. Row-locked inside a transaction because
        // a room order's stock deduction happens right here — two
        // concurrent clicks (from either surface) must not deduct twice.
        $order = DB::transaction(function () use ($orderId, $actorUserId) {
            $order = Order::with(['items.product', 'items.menuItem.recipes.ingredient', 'table', 'booking.room'])
                ->where('status', 'pending')
                ->where('destination', 'kitchen')
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

        $itemList = $order->items->map(fn ($item) => "{$item->quantity}x {$item->product_name}")->join(', ');

        $staffUsers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['super_admin', 'chef', 'waiter', 'porter']);
        })->get();

        foreach ($staffUsers as $staffUser) {
            Notification::make()
                ->title("Order #{$order->order_number} Ready!")
                ->body("Order #{$order->id} for {$order->origin_label}\n\rItems: {$itemList}\n\r is ready for pickup.")
                ->success()
                ->actions([
                    Action::make('view')
                        ->button()
                        ->url(OrderResource::getUrl('view', ['record' => $order->id])),
                ])
                ->sendToDatabase($staffUser);
        }

        return $order;
    }
}
