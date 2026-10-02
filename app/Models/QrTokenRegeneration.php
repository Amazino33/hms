<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One replaced QR code (Phase 1B): which table/room, who, why, when.
 * Append-only — the model refuses updates and deletes — and it never holds
 * the old token.
 */
class QrTokenRegeneration extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('QR token regeneration log rows are append-only.');
        });

        static::deleting(function () {
            throw new \LogicException('QR token regeneration log rows are append-only.');
        });
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function regeneratedBy()
    {
        return $this->belongsTo(User::class, 'regenerated_by');
    }
}
