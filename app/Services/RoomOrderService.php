<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FolioLine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A room order is billed to the folio at creation time (like a normal
 * restaurant order), but — unlike a normal order — never deducts stock at
 * that moment; OrderSplitter is told to defer it (see
 * OrderSplitter::handle()'s defer_stock_deduction option) until the
 * kitchen/bar display marks it Ready. There is no separate "booking
 * token": only a room with an actively checked-in booking can take a room
 * order at all, which is itself the authorization check.
 */
class RoomOrderService
{
    /** May cancel a room order that hasn't been made yet — nothing is lost. */
    public const CANCEL_BEFORE_READY_ROLES = ['receptionist', 'manager', 'admin', 'super_admin'];

    /** May cancel one that has been cooked/poured — that is a real loss. */
    public const CANCEL_AFTER_READY_ROLES = ['manager', 'admin', 'super_admin'];

    /** Order item ids by cart key from the last placeOrder() — see OrderSplitter::$lastLineItemIds. */
    public array $lastLineItemIds = [];

    /**
     * @param  array  $cart  same shape OrderSplitter::handle() expects: keyed
     *                       by product id (or "menu_{id}") => ['name','price','quantity']
     * @param  array  $options  extra OrderSplitter options (Phase 5: the guest
     *                          request marker, so a guest's locked price, chips and
     *                          note reach the order). The room-order essentials —
     *                          booking, deferred stock, pending — always win.
     * @param  ?string  $chargeNote  appended to each folio line's description,
     *                               e.g. the guest request ref "R7-0423"
     * @return array created Order models
     */
    public function placeOrder(int $roomId, array $cart, int $userId, array $options = [], ?string $chargeNote = null): array
    {
        $booking = Booking::where('room_id', $roomId)->currentlyCheckedIn()->first();

        if (! $booking) {
            throw new \Exception('This room has no checked-in guest to bill a room order to.');
        }

        $splitter = new OrderSplitter;
        $orders = $splitter->handle($cart, null, $userId, array_merge($options, [
            'booking_id' => $booking->id,
            'defer_stock_deduction' => true,
            'status' => 'pending',
        ]));
        $this->lastLineItemIds = $splitter->lastLineItemIds;

        $folio = $booking->folio ?? $booking->folio()->create();

        // One charge per order (Phase 0C), each naming its order, so a
        // cancelled food order takes back exactly the food and leaves the
        // drinks billed — and vice versa.
        foreach ($orders as $order) {
            FolioLine::create([
                'folio_id' => $folio->id,
                'order_id' => $order->id,
                'type' => 'order',
                'amount' => $order->total_amount,
                'description' => "Room order ({$order->order_number})".($chargeNote ? " · {$chargeNote}" : ''),
                'created_by' => $userId,
            ]);
        }

        return $orders;
    }

    /**
     * Cancels a room order and takes its charge back off the folio, in one
     * transaction. Stock follows the same rules as any cancelled order,
     * via OrderObserver: nothing to give back before Mark Ready (nothing
     * left the shelf), drinks restocked after it, and cooked food recorded
     * as kitchen waste — never restocked.
     *
     * A charge posted before Phase 0C covers every order of that room
     * order, so cancelling any one of them cancels all of them together
     * and reverses that charge once.
     *
     * @return Collection<int, Order> every order that was cancelled
     *
     * @throws \Exception with a message written for the person at the desk
     */
    public function cancel(Order $order, User $actor, string $reason): Collection
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('A reason is required to cancel a room order.');
        }

        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if (! $order->booking_id || $order->is_return) {
                throw new \Exception('Only a room order can be cancelled here.');
            }

            [$charge, $orders] = $this->chargeAndOrdersFor($order);

            foreach ($orders as $each) {
                $this->assertCancellable($each, $actor);
            }

            foreach ($orders as $each) {
                // OrderObserver::updating() does the stock side from here.
                $each->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => $reason,
                    'processed_by_user_id' => $actor->id,
                ]);
            }

            (new FolioService)->reverseOrderCharge($charge, $reason, $actor->id);

            return $orders;
        });
    }

    /**
     * Who may cancel this order right now — the same rule cancel() enforces,
     * for the UI to decide whether to offer the button at all.
     */
    public function canCancel(Order $order, User $actor): bool
    {
        try {
            $this->assertCancellable($order, $actor);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function assertCancellable(Order $order, User $actor): void
    {
        if (! in_array($order->status, ['pending', 'preparing', 'ready', 'served'], true)) {
            throw new \Exception("Order {$order->order_number} is already {$order->status} and can't be cancelled.");
        }

        $made = ! in_array($order->status, ['pending', 'preparing'], true);
        $roles = $made ? self::CANCEL_AFTER_READY_ROLES : self::CANCEL_BEFORE_READY_ROLES;

        if (! $actor->hasRole($roles)) {
            throw new \Exception($made
                ? "Order {$order->order_number} has already been made. Only a manager can cancel it now."
                : 'Only reception or a manager can cancel a room order.');
        }
    }

    /**
     * @return array{0: FolioLine, 1: Collection<int, Order>}
     */
    private function chargeAndOrdersFor(Order $order): array
    {
        $folio = $order->booking?->folio;

        if (! $folio) {
            throw new \Exception('This room order has no folio charge to reverse.');
        }

        $charges = $folio->lines()->where('type', 'order')->whereNull('reversal_of_line_id')->get();

        // Since Phase 0C: the charge names its order.
        $charge = $charges->firstWhere('order_id', $order->id);

        if ($charge) {
            return [$charge, collect([$order])];
        }

        // Before it: one charge per room order, naming every split order in
        // its description. Every order it names is cancelled together.
        $charge = $charges->first(fn (FolioLine $line) => is_null($line->order_id) && str_contains($line->description, $order->order_number));

        if (! $charge) {
            throw new \Exception('No folio charge for this room order was found. Ask a manager to post an adjustment instead.');
        }

        $orders = Order::where('booking_id', $order->booking_id)
            ->where('is_return', false)
            ->lockForUpdate()
            ->get()
            ->filter(fn (Order $each) => str_contains($charge->description, $each->order_number))
            ->values();

        return [$charge, $orders];
    }
}
