<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class StockTransfer extends Model
{
    use LogsActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('stock_transfer')
            ->dontLogEmptyChanges();
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function ingredientItems()
    {
        return $this->hasMany(IngredientTransferItem::class);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(WareHouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(WareHouse::class, 'to_warehouse_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
