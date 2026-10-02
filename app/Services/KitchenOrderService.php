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
 *
 * Since Phase 0D this is also THE moment kitchen food leaves the shelf, for
 * every kitchen ticket — dine-in and room alike. There is no second
 * deduction routine: anything headed for the kitchen screen is created
 * without deducting (OrderSplitter), and this is the one place it happens.
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
        // the "Ready!" notification. Row-locked inside a transaction
        // because the stock deduction happens right here — two concurrent
        // clicks (from either surface) must not deduct twice.
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

            // stock_deducted_at is the single record of whether this
            // ticket's stock has left the shelf: null for anything created
            // since Phase 0D (and every room order, always), set for an
            // older dine-in ticket that already deducted at creation —
            // which must not be charged a second time here. Read from the
            // row just locked above, so it's never a stale copy.
            if (is_null($order->stock_deducted_at)) {
                // The dish is already cooked: a shortage is recorded, never
                // a reason to refuse marking it ready.
                $shortfalls = InventoryService::deductInventoryForOrderItems($order, allowShortfall: true);

                if (! empty($shortfalls)) {
                    activity('inventory')
                        ->performedOn($order)
                        ->causedBy(User::find($actorUserId))
                        ->withProperties(['shortfalls' => $shortfalls])
                        ->log('Kitchen stock went short at Mark Ready');
                }
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
