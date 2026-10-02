<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Call waiter" from a guest's phone (Phase 4, D22). Shown on the waiter
 * strip until someone taps "On my way" or 15 minutes pass. Written only by
 * GuestWaiterCallService.
 */
class GuestWaiterCall extends Model
{
    public const UPDATED_AT = null;

    public const REASONS = [
        'ice' => 'Ice',
        'cups' => 'Cups',
        'bill' => 'Bill',
        'cutlery' => 'Cutlery',
        'other' => 'Other',
    ];

    /** What each kind of place may ask for (D22, D28). */
    public const TABLE_REASONS = ['ice', 'cups', 'bill', 'other'];

    public const ROOM_REASONS = ['ice', 'cups', 'cutlery', 'other'];

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_EXPIRED = 'expired';

    public const EXPIRES_AFTER_MINUTES = 15;

    protected $guarded = [];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function session()
    {
        return $this->belongsTo(GuestTableSession::class, 'guest_table_session_id');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    /** Open and not yet past its 15 minutes (the sweep may not have run). */
    public function scopeLive($query)
    {
        return $query->where('status', self::STATUS_OPEN)
            ->where('created_at', '>', now()->subMinutes(self::EXPIRES_AFTER_MINUTES));
    }

    public function placeName(): string
    {
        return $this->room_id ? 'Room '.$this->room?->number : (string) $this->getRelationValue('table')?->name;
    }

    public function label(): string
    {
        return $this->reason === 'other' && $this->note ? $this->note : (self::REASONS[$this->reason] ?? ucfirst($this->reason));
    }
}
