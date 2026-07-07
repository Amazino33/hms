<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class WareHouse extends Model
{
    protected $table = 'warehouses';

    protected $guarded = [];

    protected $casts = [
        'type' => 'string',
        'sub_location_labels' => 'array',
    ];

    protected static function booted(): void
    {
        // InventoryService caches "which warehouse is the bar/kitchen" for
        // an hour since it's read on every POS click — bust it immediately
        // if a warehouse's type (or existence) changes instead of waiting.
        static::saved(fn () => static::forgetWarehouseIdCache());
        static::deleted(fn () => static::forgetWarehouseIdCache());
    }

    private static function forgetWarehouseIdCache(): void
    {
        Cache::forget('inventory_service:bar_warehouse_id');
        Cache::forget('inventory_service:kitchen_warehouse_id');
    }

    /**
     * The 3 fixed sub-location slots counters see during a count session at
     * this warehouse. Falls back to sensible defaults (Bar-named warehouses
     * get Fridge/Floor/Shelf; everything else gets generic shelf labels) so
     * a manager only needs to configure this when the defaults don't fit.
     */
    public function subLocationLabels(): array
    {
        // The edit form always submits 3 slots even when left blank (as
        // nulls/empty strings), so filter those out before deciding whether
        // anything was actually configured.
        $configured = array_values(array_filter(
            $this->sub_location_labels ?? [],
            fn ($label) => is_string($label) && trim($label) !== ''
        ));

        if (count($configured) === 3) {
            return $configured;
        }

        if (str_contains(strtolower($this->name), 'bar')) {
            return ['Fridge', 'Floor', 'Shelf'];
        }

        return ['Shelf A', 'Shelf B', 'Shelf C'];
    }
}
