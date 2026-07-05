<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Ingredient extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['name', 'sku', 'unit_name', 'quantity', 'cost_per_unit', 'category'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('ingredient')
            ->dontLogEmptyChanges();
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function inventory()
    {
        return $this->hasMany(IngredientInventoryItem::class);
    }

    public function transactions()
    {
        return $this->hasMany(IngredientTransaction::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(WareHouse::class, 'ingredient_inventory_items', 'ingredient_id', 'warehouse_id')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Live stock across all warehouses, from ingredient_inventory_items —
     * the real source of truth now that InventoryService no longer touches
     * the legacy `quantity` column.
     */
    public function getCurrentStockAttribute(): float
    {
        return (float) $this->inventory()->sum('quantity');
    }
}