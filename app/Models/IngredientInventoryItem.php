<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class IngredientInventoryItem extends Model
{
    use LogsActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('ingredient_inventory_item')
            ->dontLogEmptyChanges();
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(WareHouse::class);
    }
}
