<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Housekeeping consumable catalog (tissue, soap, etc.) — a third stock
 * track deliberately parallel to Product (bar) and Ingredient (kitchen),
 * not reused from either.
 */
class RoomSupply extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'cost_per_unit' => 'decimal:2',
        'units_per_purchase_unit' => 'integer',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('room_supply')
            ->dontLogEmptyChanges();
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(RoomSupplyInventoryItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RoomSupplyTransaction::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(BookingRoomSupplyUsage::class);
    }

    public function getCurrentStockAttribute(): float
    {
        return (float) $this->inventory()->sum('quantity');
    }
}
