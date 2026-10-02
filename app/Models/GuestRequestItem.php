<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a guest request (Phase 2) — a snapshot: the name and price
 * the guest saw (the price lock), and the chip LABELS they picked.
 * Created only by GuestRequestService.
 */
class GuestRequestItem extends Model
{
    public const STATUSES = ['pending', 'at_bar', 'needs_waiter', 'released', 'ordered', 'removed', 'cancelled'];

    /** Lines still waiting on staff — no order and no stock exist for them yet. */
    public const WAITING = ['at_bar', 'needs_waiter'];

    // Room delivery (Phase 5) — written only by RoomDeliveryService.
    public const AWAITING_DISPATCH = 'awaiting_dispatch';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const REFUSED = 'refused';

    protected $guarded = [];

    protected $casts = [
        'chips' => 'array',
        'unit_price_snapshot' => 'decimal:2',
        'released_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(GuestRequest::class, 'guest_request_id');
    }

    /** The real order this line became (Phase 3) — null until then. */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function porter()
    {
        return $this->belongsTo(User::class, 'porter_user_id');
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function finalQuantity(): int
    {
        return $this->quantity_final ?? $this->quantity_requested;
    }

    public function lineTotal(): float
    {
        return round((float) $this->unit_price_snapshot * ($this->quantity_final ?? $this->quantity_requested), 2);
    }
}
