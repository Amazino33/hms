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

    protected $fillable = [
        'name', 'sku', 'unit_name', 'quantity', 'cost_per_unit', 'category',
        'purchase_unit_name', 'units_per_purchase_unit', 'created_by_staff', 'created_by',
    ];

    protected $casts = [
        'units_per_purchase_unit' => 'integer',
        'created_by_staff' => 'boolean',
    ];

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

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function procurementIngredientItems()
    {
        return $this->hasMany(ProcurementIngredientItem::class);
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