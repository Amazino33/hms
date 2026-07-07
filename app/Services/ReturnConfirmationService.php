<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReturnConfirmationService
{
    private const ROLE_FOR_DESTINATION = [
        'bar' => 'bartender',
        'kitchen' => 'chef',
    ];

    /**
     * Confirming physically receiving the item back is what makes it a
     * return at all. Before this, the guest's bill has NOT changed —
     * confirmation is the only thing that (a) drops the amount from the
     * original order and (b) reverses stock, both in one transaction, both
     * under the confirming custodian's own accountable shift.
     *
     * @throws \Exception
     */
    public function confirm(Order $returnTicket, User $confirmingUser): Order
    {
        $role = self::ROLE_FOR_DESTINATION[$returnTicket->destination] ?? null;

        if (!$role) {
            throw new \Exception('Confirmed returns are only supported for bar and kitchen destinations.');
        }

        $hasActiveShift = Shift::query()
            ->where('user_id', $confirmingUser->id)
            ->ofType($role)
            ->activeNonStale($role)
            ->exists();

        if (!$hasActiveShift) {
            throw new \Exception("You must have an active {$role} shift to confirm this return.");
        }

        return DB::transaction(function () use ($returnTicket, $confirmingUser) {
            // Locked and re-checked inside the transaction — without this,
            // two near-simultaneous confirm taps (a double-click, or two
            // staff on two screens) can both observe status='pending'
            // before either write lands, and both restock/reduce the bill.
            $returnTicket = Order::query()->lockForUpdate()->findOrFail($returnTicket->id);
            $this->assertPendingReturnTicket($returnTicket);

            $this->reduceOriginalOrder($returnTicket);

            // Flipping to 'returned' triggers OrderObserver's centralized
            // restock (InventoryTransaction/IngredientTransaction) — no
            // separate stock mutation path here.
            $returnTicket->update([
                'status' => 'returned',
                'processed_by_user_id' => $confirmingUser->id,
            ]);

            return $returnTicket->fresh();
        });
    }

    /**
     * The bartender/chef never actually got the item back (guest kept it,
     * waiter was mistaken, etc). The original order was never touched, so
     * there's nothing to undo there — this just closes the ticket out.
     *
     * @throws \Exception
     */
    public function reject(Order $returnTicket, User $rejectingUser, string $reason): Order
    {
        return DB::transaction(function () use ($returnTicket, $rejectingUser, $reason) {
            $returnTicket = Order::query()->lockForUpdate()->findOrFail($returnTicket->id);
            $this->assertPendingReturnTicket($returnTicket);

            $returnTicket->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'processed_by_user_id' => $rejectingUser->id,
            ]);

            return $returnTicket->fresh();
        });
    }

    private function assertPendingReturnTicket(Order $returnTicket): void
    {
        if (!$returnTicket->is_return) {
            throw new \Exception('This order is not a return ticket.');
        }

        if ($returnTicket->status !== 'pending') {
            throw new \Exception('This return has already been resolved.');
        }
    }

    /**
     * Same matching/reduction logic the old immediate-effect flow used —
     * just deferred to run here, at confirmation time, instead of at the
     * moment the waiter asked for the return.
     */
    private function reduceOriginalOrder(Order $returnTicket): void
    {
        $returnItem = $returnTicket->items->first();

        if (!$returnItem) {
            return;
        }

        $qtyToReturn = $returnItem->quantity;

        $activeOrders = Order::where('table_id', $returnTicket->table_id)
            ->where('is_return', false)
            ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
            ->with('items')
            ->get();

        $touchedOrderIds = [];

        foreach ($activeOrders as $activeOrder) {
            foreach ($activeOrder->items as $orderItem) {
                if ($qtyToReturn <= 0) {
                    break;
                }

                $isMatch = ($returnItem->item_type === 'product' && $orderItem->product_id == $returnItem->product_id)
                    || ($returnItem->item_type === 'menu_item' && $orderItem->menu_item_id == $returnItem->menu_item_id);

                if (!$isMatch) {
                    continue;
                }

                $deductAmount = min($orderItem->quantity, $qtyToReturn);
                $newQty = $orderItem->quantity - $deductAmount;

                if ($newQty > 0) {
                    $orderItem->update(['quantity' => $newQty, 'subtotal' => $newQty * $orderItem->unit_price]);
                } else {
                    $orderItem->delete();
                }

                $qtyToReturn -= $deductAmount;
                $touchedOrderIds[$activeOrder->id] = true;
            }
        }

        foreach (array_keys($touchedOrderIds) as $orderId) {
            $newTotal = OrderItem::where('order_id', $orderId)->sum('subtotal');
            Order::where('id', $orderId)->update(['total_amount' => $newTotal]);
        }
    }
}
