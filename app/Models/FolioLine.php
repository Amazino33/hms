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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('folio_line')
            ->dontLogEmptyChanges();
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
