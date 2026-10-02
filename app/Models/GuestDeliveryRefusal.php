<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A room delivery the guest refused (D26). Nothing is reversed when it is
 * recorded: drinks wait for the bar to confirm the bottles came back, then
 * a manager approves (the room order is cancelled and its folio charge
 * reversed) or rejects (the charge stands).
 *
 * Append-only: never deleted, and only its own status steps change, in
 * order, through RoomDeliveryService.
 */
class GuestDeliveryRefusal extends Model
{
    public const UPDATED_AT = null;

    public const AWAITING_BAR_RETURN = 'awaiting_bar_return';

    public const AWAITING_MANAGER = 'awaiting_manager';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Each status may only move to these. */
    private const NEXT = [
        self::AWAITING_BAR_RETURN => [self::AWAITING_MANAGER],
        self::AWAITING_MANAGER => [self::APPROVED, self::REJECTED],
    ];

    private const STEP_FIELDS = ['status', 'bar_return_confirmed_by_user_id', 'bar_return_confirmed_at', 'decided_by_user_id', 'decided_at', 'decision_note'];

    protected $guarded = [];

    protected $casts = [
        'line_ids' => 'array',
        'bar_return_confirmed_at' => 'datetime',
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (GuestDeliveryRefusal $refusal) {
            $allowed = self::NEXT[$refusal->getOriginal('status')] ?? [];

            if (! in_array($refusal->status, $allowed, true) || array_diff(array_keys($refusal->getDirty()), self::STEP_FIELDS) !== []) {
                throw new \LogicException('A delivery refusal only moves forward one step, through RoomDeliveryService.');
            }
        });

        static::deleting(fn () => throw new \LogicException('Delivery refusals are append-only.'));
    }

    public function request()
    {
        return $this->belongsTo(GuestRequest::class, 'guest_request_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function barReturnConfirmedBy()
    {
        return $this->belongsTo(User::class, 'bar_return_confirmed_by_user_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::AWAITING_BAR_RETURN, self::AWAITING_MANAGER], true);
    }
}
