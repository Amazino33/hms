<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\WareHouse;
use App\Models\Category;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Product extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'price', 'cost_price', 'category_id', 'is_active', 'fridge_par'])
            ->logOnlyDirty()
            ->useLogName('product')
            ->dontLogEmptyChanges();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function inventory()
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(WareHouse::class, 'inventory_items')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }

    public function warehouse()
    {
        return $this->belongsToMany(WareHouse::class, 'inventory_items')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }

    public function transactions() {
        return $this->hasMany(InventoryTransaction::class);
    }
}
