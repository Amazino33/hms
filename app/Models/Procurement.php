<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Procurement extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'purchased_at' => 'date',
        'total_cost' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('procurement')
            ->dontLogEmptyChanges();
    }

    public function location()
    {
        return $this->belongsTo(WareHouse::class, 'location_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function items()
    {
        return $this->hasMany(ProcurementItem::class);
    }

    public function ingredientItems()
    {
        return $this->hasMany(ProcurementIngredientItem::class);
    }
}
