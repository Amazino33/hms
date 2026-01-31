<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sku', 'type', 'sale_price', 'commission_amount', 'available_for_sale'];

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function ingredients()
    {
        return $this->belongsToMany(Ingredient::class, 'recipes')->withPivot('quantity_needed');
    }

    // Calculate total recipe cost
    public function getTotalRecipeCostAttribute()
    {
        return $this->recipes->sum(function ($recipe) {
            return $recipe->quantity_needed * $recipe->ingredient->cost_per_unit;
        });
    }
}