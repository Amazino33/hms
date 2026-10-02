<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One chip ("Cold", "Extra pepper"). Renaming it changes what future
 * guests see; order items already placed keep the label they were given.
 */
class ChipOption extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('chip_option')
            ->dontLogEmptyChanges();
    }

    public function group()
    {
        return $this->belongsTo(ChipGroup::class, 'chip_group_id');
    }
}
