<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $guarded = [];

    protected $casts = [
        'nightly_rate' => 'decimal:2',
        'deposit' => 'decimal:2',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'checkout_snapshot' => 'array',
    ];

    // Deliberately not Eloquent's built-in 'date' cast: that cast still
    // stores using the model's global datetime format ("Y-m-d H:i:s"),
    // which SQLite (unlike MySQL's DATE column) stores verbatim — breaking
    // every exact-day string-equality query in this module (occupancy
    // derivation, auto-release). These accessors guarantee a pure "Y-m-d"
    // string in the database on every engine, while still handing back a
    // real Carbon instance on read.
    protected function checkIn(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Carbon::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d') : null,
        );
    }

    protected function checkOut(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Carbon::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d') : null,
        );
    }

    // No longer flips room.status on create/delete — occupancy is derived
    // live from bookings (Room::occupancyState()), never stored. The old
    // hook only handled create/delete anyway (never edit, never an actual
    // checkout event), which is exactly why rooms used to stay "occupied"
    // forever unless someone deleted the booking row outright.

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function folio()
    {
        return $this->hasOne(Folio::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function isReserved(): bool
    {
        return $this->status === 'reserved';
    }

    public function isCheckedIn(): bool
    {
        return $this->status === 'checked_in';
    }

    public function isCheckedOut(): bool
    {
        return $this->status === 'checked_out';
    }

    public function hasDeposit(): bool
    {
        return $this->deposit !== null && (float) $this->deposit > 0;
    }
}
