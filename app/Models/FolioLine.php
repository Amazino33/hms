<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Immutable — a folio line is never updated once created. Corrections are
 * always a new adjustment/reversal line, never an edit to an existing one.
 * Positive amount = charge (increases balance owed), negative = payment/
 * credit (decreases it) — the folio's balance is a plain sum of lines.
 */
class FolioLine extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    /**
     * The only fields that ever change after a line is written: a manager
     * resolving a transfer PAYMENT (FolioService::verifyTransfer(),
     * rejectTransfer(), and voidLine()'s transfer branch). Everything else
     * on every line — and anything at all on a charge — is fixed for good.
     */
    public const PAYMENT_RESOLUTION_FIELDS = ['verified', 'verified_by', 'verified_at', 'reference', 'updated_at'];

    /**
     * Enforced here rather than trusted to every caller: a folio is the
     * guest's bill, and a quietly edited or deleted charge is
     * indistinguishable from fraud after the fact. Corrections go through
     * a reversal line (FolioService).
     */
    protected static function booted(): void
    {
        static::updating(function (FolioLine $line) {
            $changed = array_keys($line->getDirty());
            $resolvesAPayment = $line->getOriginal('type') === 'payment'
                && array_diff($changed, self::PAYMENT_RESOLUTION_FIELDS) === [];

            if (! $resolvesAPayment) {
                throw new \LogicException('Folio lines are immutable — post a reversal line instead of editing this one.');
            }
        });

        static::deleting(function () {
            throw new \LogicException('Folio lines are never deleted — post a reversal line instead.');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('folio_line')
            ->dontLogEmptyChanges();
    }

    /** The room order this charge (or its reversal) is for — null on lines posted before Phase 0C. */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** The line that voided this one, if a receptionist reversed it. */
    public function reversal()
    {
        return $this->hasOne(FolioLine::class, 'reversal_of_line_id');
    }

    /** The line this one was posted to void, if it is itself a reversal. */
    public function reversalOf()
    {
        return $this->belongsTo(FolioLine::class, 'reversal_of_line_id');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_line_id !== null;
    }

    public function isVoided(): bool
    {
        return $this->relationLoaded('reversal')
            ? $this->reversal !== null
            : $this->reversal()->exists();
    }
}
