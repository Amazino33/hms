<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sold-out / available flip made from the KDS (Phase 1A). Append-only:
 * written by MenuAvailabilityService, never updated or deleted — the model
 * refuses both, so the history can't be rewritten.
 */
class MenuItemAvailabilityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'from' => 'boolean',
        'to' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Availability log rows are append-only.');
        });

        static::deleting(function () {
            throw new \LogicException('Availability log rows are append-only.');
        });
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
