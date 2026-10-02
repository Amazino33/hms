<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A guest sitting moved to another table (Phase 4, D4). Append-only: the
 * model refuses updates and deletes. Written only by TableMoveService.
 */
class TableMove extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'order_ids' => 'array',
        'request_ids' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Table moves are append-only.'));
        static::deleting(fn () => throw new \LogicException('Table moves are append-only.'));
    }

    public function session()
    {
        return $this->belongsTo(GuestTableSession::class, 'guest_table_session_id');
    }

    public function fromTable()
    {
        return $this->belongsTo(Table::class, 'from_table_id');
    }

    public function toTable()
    {
        return $this->belongsTo(Table::class, 'to_table_id');
    }

    public function movedBy()
    {
        return $this->belongsTo(User::class, 'moved_by_user_id');
    }
}
