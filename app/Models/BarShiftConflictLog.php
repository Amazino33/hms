<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * More than one bartender shift open at once (D1). Append-only, except
 * GuestBarMonitor resolves it exactly once (resolved_at).
 */
class BarShiftConflictLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'shift_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (BarShiftConflictLog $log) {
            if ($log->getOriginal('resolved_at') !== null || array_diff(array_keys($log->getDirty()), ['resolved_at', 'updated_at']) !== []) {
                throw new \LogicException('A bar shift conflict log is only ever resolved once, by GuestBarMonitor.');
            }
        });

        static::deleting(fn () => throw new \LogicException('Bar shift conflict logs are append-only.'));
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }
}
