<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a guest sent from their phone (Phase 2). A REQUEST, never an order:
 * it touches no stock, bill or ledger until staff confirm it (Phase 3).
 * Created only by GuestRequestService.
 */
class GuestRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED_BY_GUEST = 'cancelled_by_guest';

    public const STATUS_CANCELLED_BY_STAFF = 'cancelled_by_staff';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED_BY_GUEST,
        self::STATUS_CANCELLED_BY_STAFF,
        self::STATUS_EXPIRED,
        self::STATUS_COMPLETED,
    ];

    /** Shown to guests and staff. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Waiting for waiter',
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_CANCELLED_BY_GUEST => 'Cancelled',
        self::STATUS_CANCELLED_BY_STAFF => 'Cancelled by staff',
        self::STATUS_EXPIRED => 'Expired',
        self::STATUS_COMPLETED => 'Completed',
    ];

    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'total_snapshot' => 'decimal:2',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'first_from_device' => 'boolean',
    ];

    /** Room requests wait for reception, not a waiter (Phase 5). */
    public const ROOM_STATUS_LABELS = [
        self::STATUS_PENDING => 'Waiting for reception',
    ];

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_NONE = 'none';

    public function items()
    {
        return $this->hasMany(GuestRequestItem::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /** The checked-in booking a room request was made during (Phase 5). */
    public function stay()
    {
        return $this->belongsTo(Booking::class, 'stay_id');
    }

    public function deliveryRefusals()
    {
        return $this->hasMany(GuestDeliveryRefusal::class);
    }

    public function isRoom(): bool
    {
        return $this->room_id !== null;
    }

    public function session()
    {
        return $this->belongsTo(GuestTableSession::class, 'guest_table_session_id');
    }

    /** The sitting's claims, calls and moves — for the read-only admin view (Phase 4). */
    public function sessionClaims()
    {
        return $this->hasMany(GuestPaymentClaim::class, 'guest_table_session_id', 'guest_table_session_id');
    }

    public function sessionWaiterCalls()
    {
        return $this->hasMany(GuestWaiterCall::class, 'guest_table_session_id', 'guest_table_session_id');
    }

    public function sessionMoves()
    {
        return $this->hasMany(TableMove::class, 'guest_table_session_id', 'guest_table_session_id');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        if ($this->isRoom() && isset(self::ROOM_STATUS_LABELS[$this->status])) {
            return self::ROOM_STATUS_LABELS[$this->status];
        }

        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    public function placeLabel(): string
    {
        // getRelationValue(): inside a model, $this->table is Eloquent's own
        // protected $table string ("guest_requests"), not the relation.
        $table = $this->getRelationValue('table');

        return $table?->name ?? ($this->room ? 'Room '.$this->room->number : '—');
    }
}
