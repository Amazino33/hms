<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class IngredientTransaction extends Model
{
    use LogsActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('ingredient_transaction')
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
