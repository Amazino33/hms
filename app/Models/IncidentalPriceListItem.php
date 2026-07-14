<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * A fixed, receptionist-facing quick-pick list ("Extra towel — 500") so
 * incidental charges stay consistent instead of every receptionist typing
 * a free-text description and amount. The folio screen still allows a
 * one-off custom charge for anything not on the list.
 */
class IncidentalPriceListItem extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('incidental_price_list_item')
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
