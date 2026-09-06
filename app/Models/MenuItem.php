<?php

namespace App\Models;

use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

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
     * Request-scoped memo of the kitchen enforcement toggle.
     *
     * Memoised here rather than inside InventoryService::
     * enforceIngredientStock(), which reads Company::find(1) uncached and
     * is documented and tested as re-reading it on every call. The
     * accessor below runs once per tile, so without a memo a 100-item POS
     * grid pays 100 primary-key lookups for a value that cannot change
     * mid-render.
     *
     * Deliberately a static method: once() keys on the calling object as
     * well as the call site, so calling it straight from the instance
     * accessor would memoise per MenuItem and save nothing. With no bound
     * object the memo is shared across every tile. The framework flushes
     * Once in its test teardown, so nothing leaks between tests.
     */
    private static function enforcementEnabled(): bool
    {
        return once(fn () => InventoryService::enforceIngredientStock());
    }

    /**
     * Maximum sellable portions, from live kitchen-warehouse ingredient
     * stock — the limiting recipe ingredient decides.
     *
     * Reads IngredientInventoryItem at the kitchen warehouse: the same
     * source InventoryService::checkMenuItemIngredientsAvailability()
     * gates on and deductMenuItemIngredients() decrements. It previously
     * read the legacy `ingredients.quantity` column, which nothing has
     * updated since InventoryService moved to per-warehouse rows (see
     * Ingredient::getCurrentStockAttribute()), so the portions figure on
     * every POS food tile was frozen at whatever the ingredient was
     * created with — while products beside them showed live stock.
     *
     * null means "unlimited", which the POS grid treats as "not a
     * stock-out" rather than as zero. Two distinct cases return it:
     *   - the item has no recipe at all (a service/untracked item), and
     *   - kitchen ingredient enforcement is off, meaning ingredient stock
     *     is not yet trusted to gate sales. Reporting a real (usually
     *     zero) figure then would grey out food tiles that the POS would
     *     nonetheless happily sell, because the actual gate in
     *     FloorPlanController honours that same toggle. Tying the display
     *     to it keeps the two in step, and makes that one switch turn
     *     kitchen stock control on end to end.
     *
     * Eager-load `recipes.ingredient.inventory` when rendering a grid of
     * these — the POS calls this once per tile.
     */
    public function getAvailableStockAttribute()
    {
        if ($this->recipes->isEmpty()) {
            return null;
        }

        if (! self::enforcementEnabled()) {
            return null;
        }

        $warehouseId = InventoryService::getKitchenWarehouseId();
        $minPortions = null;

        foreach ($this->recipes as $recipe) {
            if (! $recipe->ingredient) {
                continue;
            }

            $needed = (float) $recipe->quantity_needed;
            if ($needed <= 0) {
                continue;
            }

            $available = (float) ($recipe->ingredient->inventory
                ->firstWhere('warehouse_id', $warehouseId)?->quantity ?? 0);
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
