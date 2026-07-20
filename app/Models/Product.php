<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Product extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'units_per_purchase_unit' => 'integer',
        'last_cost_price' => 'decimal:2',
        'created_by_staff' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'sku', 'price', 'cost_price', 'category_id', 'is_active', 'fridge_par',
                'base_unit', 'purchase_unit_name', 'units_per_purchase_unit', 'last_cost_price', 'created_by_staff',
            ])
            ->logOnlyDirty()
            ->useLogName('product')
            ->dontLogEmptyChanges();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function procurementItems()
    {
        return $this->hasMany(ProcurementItem::class);
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

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function stockAdjustments()
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function countSessionItems()
    {
        return $this->hasMany(CountSessionItem::class);
    }

    public function deletionRequests()
    {
        return $this->hasMany(ProductDeletionRequest::class);
    }
}
