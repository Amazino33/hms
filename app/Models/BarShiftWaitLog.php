<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Guest drinks waiting with no bartender shift open (Phase 3, design §10).
 * Append-only, except GuestBarMonitor may set ended_at, max_waiting_lines
 * and alerted_manager_at while the log is open — never after it closes,
 * and never anything else.
 */
class BarShiftWaitLog extends Model
{
    public const MONITOR_FIELDS = ['ended_at', 'max_waiting_lines', 'alerted_manager_at', 'updated_at'];

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'alerted_manager_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (BarShiftWaitLog $log) {
            if ($log->getOriginal('ended_at') !== null || array_diff(array_keys($log->getDirty()), self::MONITOR_FIELDS) !== []) {
                throw new \LogicException('A bar shift wait log only changes while open, through GuestBarMonitor.');
            }
        });

        static::deleting(fn () => throw new \LogicException('Bar shift wait logs are append-only.'));
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('ended_at');
    }
}
