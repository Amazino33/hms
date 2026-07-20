<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class InventoryTransaction extends Model
{
    use LogsActivity;

    // Allow mass assignment for all fields
    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('inventory_transaction')
            ->dontLogEmptyChanges();
    }

    // Relations
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
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