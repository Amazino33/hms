<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A guest table session handed from one waiter to another (D3), so the
 * first can end their shift. Append-only: the model refuses updates and
 * deletes. Written only by GuestSessionHandoverService.
 */
class GuestSessionHandover extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'request_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Guest session handovers are append-only.'));
        static::deleting(fn () => throw new \LogicException('Guest session handovers are append-only.'));
    }

    public function session()
    {
        return $this->belongsTo(GuestTableSession::class, 'guest_table_session_id');
    }
}
