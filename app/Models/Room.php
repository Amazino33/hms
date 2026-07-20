<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $guarded = [];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * `status` only ever means "under maintenance" now (or plain
     * available) — occupancy is never stored here, it's derived live from
     * bookings via occupancyState()/isOccupiedToday() below. Kept as a
     * scope (not a maintenance-only rename) to avoid an enum migration
     * for a value ('occupied') nothing writes anymore.
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', '!=', 'maintenance');
    }

    /**
     * Vacant / Occupied / Due Out Today / Arriving Today — derived live
     * from bookings, never stored. Status-aware (not just date-range) as
     * of the hotel module's Step 9: a 'reserved' booking whose check-in
     * date has quietly passed without an actual check-in is an overdue
     * arrival, not a physically occupied room — and a cancelled/no_show
     * booking must never make a room look occupied at all. 'occupied'/
     * 'due_out_today' therefore require status = 'checked_in';
     * 'arriving_today' requires status = 'reserved'.
     */
    public function occupancyState(): string
    {
        $today = now()->toDateString();

        if ($this->bookings()->where('status', 'checked_in')->where('check_out', $today)->exists()) {
            return 'due_out_today';
        }

        if ($this->bookings()->where('status', 'checked_in')
            ->where('check_in', '<=', $today)->where('check_out', '>=', $today)->exists()) {
            return 'occupied';
        }

        if ($this->bookings()->where('status', 'reserved')->where('check_in', $today)->exists()) {
            return 'arriving_today';
        }

        return 'vacant';
    }

    /**
     * Whether this room has any booking touching today at all (arriving,
     * occupied, or due out) — used to keep a room out of the "pick a room"
     * list for a new booking starting today. Full date-range overlap
     * prevention for future-dated bookings is Step 2's job.
     */
    public function isOccupiedToday(): bool
    {
        return in_array($this->occupancyState(), ['occupied', 'due_out_today', 'arriving_today'], true);
    }
}
