<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A guest saying "I've paid" by transfer (Phase 4). Information for the
 * waiter's Mark Paid, never a payment itself.
 *
 * Append-only (D23): the row is never edited or deleted, except that its
 * status moves exactly once from 'open' — to 'withdrawn' by the guest, or
 * to 'matched' / 'unmatched' when the table is paid. Written only by
 * GuestClaimService and GuestTablePaymentService.
 */
class GuestPaymentClaim extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_OPEN = 'open';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_UNMATCHED = 'unmatched';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'status_set_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (GuestPaymentClaim $claim) {
            $changed = array_diff(array_keys($claim->getDirty()), ['status', 'status_set_at']);

            if ($claim->getOriginal('status') !== self::STATUS_OPEN || $changed !== []) {
                throw new \LogicException('A payment claim is append-only: only an open claim\'s status may be set, once.');
            }
        });

        static::deleting(fn () => throw new \LogicException('Payment claims are append-only.'));
    }

    public function session()
    {
        return $this->belongsTo(GuestTableSession::class, 'guest_table_session_id');
    }

    /** A room claim's stay (Phase 5); null for a table claim. */
    public function stay()
    {
        return $this->belongsTo(Booking::class, 'stay_id');
    }

    public function transferAccount()
    {
        return $this->belongsTo(TransferAccount::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
