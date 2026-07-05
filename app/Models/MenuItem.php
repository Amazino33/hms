<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class MenuItem extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['name', 'sku', 'category_id', 'type', 'sale_price', 'available_for_sale'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('menu_item')
            ->dontLogEmptyChanges();
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function ingredients()
    {
        return $this->belongsToMany(Ingredient::class, 'recipes')->withPivot('quantity_needed');
    }

    /**
     * Calculate maximum available portions based on ingredient stock.
     * Returns null if no ingredients are defined (assumed unlimited/service).
     */
    public function getAvailableStockAttribute()
    {
        if ($this->recipes->isEmpty()) {
            return null;
        }

        $minPortions = null;

        foreach ($this->recipes as $recipe) {
            if (!$recipe->ingredient) continue;
            
            $needed = (float) $recipe->quantity_needed;
            if ($needed <= 0) continue;

            $available = (float) $recipe->ingredient->quantity;
            $portions = floor($available / $needed);
            
            if (is_null($minPortions) || $portions < $minPortions) {
                $minPortions = (int) $portions;
            }
        }

        return $minPortions ?? 0;
    }

    // Calculate total recipe cost
    public function getTotalRecipeCostAttribute()
    {
        return $this->recipes->sum(function ($recipe) {
            return $recipe->quantity_needed * $recipe->ingredient->cost_per_unit;
        });
    }
}