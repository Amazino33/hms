<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * unit_cost_at_use is a snapshot — a later change to room_supplies.
 * cost_per_unit never retroactively changes a past stay's recorded cost.
 */
class BookingRoomSupplyUsage extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost_at_use' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('booking_room_supply_usage')
            ->dontLogEmptyChanges();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function roomSupply(): BelongsTo
    {
        return $this->belongsTo(RoomSupply::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(RoomSupplyTransaction::class, 'room_supply_transaction_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function totalCost(): float
    {
        return (float) $this->quantity * (float) $this->unit_cost_at_use;
    }
}
