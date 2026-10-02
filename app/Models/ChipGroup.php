<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A set of quick choices offered on a category's items (Phase 1A) —
 * "Temperature" (pick one) on drinks, "Food extras" (pick any) on food.
 */
class ChipGroup extends Model
{
    use LogsActivity;

    public const SELECTIONS = ['single' => 'Pick one', 'multiple' => 'Pick any'];

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('chip_group')
            ->dontLogEmptyChanges();
    }

    public function options()
    {
        return $this->hasMany(ChipOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}
