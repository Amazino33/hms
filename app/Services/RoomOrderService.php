<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FolioLine;

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
    /**
     * @param array $cart same shape OrderSplitter::handle() expects: keyed
     *   by product id (or "menu_{id}") => ['name','price','quantity']
     * @return array created Order models
     */
    public function placeOrder(int $roomId, array $cart, int $userId): array
    {
        $booking = Booking::where('room_id', $roomId)->where('status', 'checked_in')->first();

        if (! $booking) {
            throw new \Exception('This room has no checked-in guest to bill a room order to.');
        }

        $orders = (new OrderSplitter())->handle($cart, null, $userId, [
            'booking_id' => $booking->id,
            'defer_stock_deduction' => true,
            'status' => 'pending',
        ]);

        $folio = $booking->folio ?? $booking->folio()->create();
        $total = collect($orders)->sum('total_amount');
        $orderNumbers = collect($orders)->pluck('order_number')->join(', ');

        FolioLine::create([
            'folio_id' => $folio->id,
            'type' => 'order',
            'amount' => $total,
            'description' => "Room order ({$orderNumbers})",
            'created_by' => $userId,
        ]);

        return $orders;
    }
}
