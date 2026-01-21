<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // When a Booking is CREATED, find the Room and mark it 'occupied'
        static::created(function ($booking) {
            if ($booking->room) {
                $booking->room->update(['status' => 'occupied']);
            }
        });

        // (Optional) When a Booking is DELETED, mark the Room 'available'
        static::deleted(function ($booking) {
             if ($booking->room) {
                $booking->room->update(['status' => 'available']);
            }
        });
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }
}
