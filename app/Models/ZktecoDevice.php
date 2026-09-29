<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A terminal's contact record — proof of life, kept in the database rather
 * than the log because production's LOG_LEVEL hides info lines.
 */
class ZktecoDevice extends Model
{
    protected $fillable = [
        'serial',
        'last_handshake_at',
        'last_poll_at',
        'last_push_at',
        'last_punch_at',
        'punches_received',
    ];

    protected $casts = [
        'last_handshake_at' => 'datetime',
        'last_poll_at' => 'datetime',
        'last_push_at' => 'datetime',
        'last_punch_at' => 'datetime',
    ];

    /**
     * Stamp one kind of contact from a device that may never have been seen
     * before. Never throws: a terminal must not stop being able to deliver
     * punches because our bookkeeping row failed to write.
     */
    public static function touchContact(?string $serial, string $column, array $extra = []): void
    {
        try {
            static::updateOrCreate(
                ['serial' => $serial ?: 'UNKNOWN'],
                array_merge([$column => now()], $extra)
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
