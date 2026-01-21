<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Warehouse;
use App\Models\Category;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

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
        return $this->belongsToMany(Warehouse::class, 'inventory_items')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }
}
