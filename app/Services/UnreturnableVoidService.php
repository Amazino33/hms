<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UnreturnableVoid;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnreturnableVoidService
{
    private const REASON_CODES = ['comp', 'complaint', 'loss', 'other'];

    /**
     * Manager-only, explicitly NOT a return: no stock ever reverses. This
     * removes the voided quantity's value from the waiter's expected
     * remittance while keeping a permanent, reasoned record for comp/loss
     * reporting — callers must verify $manager actually holds a manager-or-
     * above role before calling this; it is not re-checked here.
     *
     * @throws \Exception
     */
    public function apply(OrderItem $item, User $manager, string $reasonCode, int $quantity, ?string $notes = null): UnreturnableVoid
    {
        if (!in_array($reasonCode, self::REASON_CODES, true)) {
            throw new \Exception('Invalid reason code.');
        }

        if ($quantity < 1 || $quantity > $item->quantity) {
            throw new \Exception('Invalid quantity.');
        }

        $order = $item->order;
        $amount = round($item->unit_price * $quantity, 2);

        return DB::transaction(function () use ($item, $order, $manager, $reasonCode, $quantity, $notes, $amount) {
            // Deliberately never deleted, even when fully voided (newQty=0)
            // — the void record's order_item_id must keep pointing at a
            // real row for the comp/loss audit trail to stay intact.
            $newQty = $item->quantity - $quantity;
            $item->update(['quantity' => $newQty, 'subtotal' => $newQty * $item->unit_price]);

            $newTotal = OrderItem::where('order_id', $order->id)->sum('subtotal');
            $order->update(['total_amount' => $newTotal]);

            return UnreturnableVoid::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'manager_id' => $manager->id,
                'reason_code' => $reasonCode,
                'notes' => $notes,
                'quantity' => $quantity,
                'amount' => $amount,
            ]);
        });
    }
}
