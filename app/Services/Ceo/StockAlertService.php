<?php

namespace App\Services\Ceo;

use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;

/**
 * Reuses the two existing, narrower low-stock definitions found during
 * the Phase 1 audit — the kiosk's hardcoded product threshold (5,
 * pos.blade.php) and InventoryService::getLowStockAlerts()'s ingredient
 * default (10) — broadened to cover every warehouse and every item, not
 * just the kiosk's assigned warehouse or menu-linked ingredients. Same
 * numbers staff already see; this view just doesn't narrow the scope the
 * way the two operational surfaces happen to.
 */
class StockAlertService
{
    public const PRODUCT_LOW_THRESHOLD = 5;

    public const INGREDIENT_LOW_THRESHOLD = 10;

    /**
     * @param array{state?: string, item_type?: string, category?: string, location?: string} $filters
     */
    public function alerts(array $filters = []): Collection
    {
        $rows = $this->productAlerts()->concat($this->ingredientAlerts());

        if (! empty($filters['state']) && $filters['state'] !== 'both') {
            $rows = $rows->where('state', $filters['state']);
        }

        if (! empty($filters['item_type'])) {
            $rows = $rows->where('item_type', $filters['item_type']);
        }

        if (! empty($filters['category'])) {
            $rows = $rows->where('category', $filters['category']);
        }

        if (! empty($filters['location'])) {
            $rows = $rows->where('location', $filters['location']);
        }

        // Sold Out first, then Low sorted by proximity to zero.
        return $rows->sort(function ($a, $b) {
            $aRank = $a['state'] === 'sold_out' ? 0 : 1;
            $bRank = $b['state'] === 'sold_out' ? 0 : 1;

            return $aRank <=> $bRank ?: $a['quantity'] <=> $b['quantity'];
        })->values();
    }

    public function counts(): array
    {
        $all = $this->alerts();

        return [
            'low' => $all->where('state', 'low')->count(),
            'sold_out' => $all->where('state', 'sold_out')->count(),
        ];
    }

    public function totalStockValueAtCost(): float
    {
        $productValue = InventoryItem::with('product')->get()
            ->sum(fn (InventoryItem $i) => (float) $i->quantity * (float) ($i->product?->cost_price ?? 0));

        $ingredientValue = IngredientInventoryItem::with('ingredient')->get()
            ->sum(fn (IngredientInventoryItem $i) => (float) $i->quantity * (float) ($i->ingredient?->cost_per_unit ?? 0));

        return round($productValue + $ingredientValue, 2);
    }

    private function productAlerts(): Collection
    {
        return InventoryItem::with(['product.category', 'warehouse'])->get()
            ->filter(fn (InventoryItem $item) => $item->product !== null)
            ->map(function (InventoryItem $item) {
                $qty = (float) $item->quantity;
                $state = $this->stateFor($qty, self::PRODUCT_LOW_THRESHOLD);

                if (! $state) {
                    return null;
                }

                return [
                    'item_type' => 'product',
                    'name' => $item->product->name,
                    'category' => $item->product->category?->name,
                    'location' => $item->warehouse?->name,
                    'quantity' => $qty,
                    'threshold' => self::PRODUCT_LOW_THRESHOLD,
                    'stock_value_at_cost' => round($qty * (float) $item->product->cost_price, 2),
                    'state' => $state,
                ];
            })->filter()->values();
    }

    private function ingredientAlerts(): Collection
    {
        return IngredientInventoryItem::with(['ingredient', 'warehouse'])->get()
            ->filter(fn (IngredientInventoryItem $item) => $item->ingredient !== null)
            ->map(function (IngredientInventoryItem $item) {
                $qty = (float) $item->quantity;
                $state = $this->stateFor($qty, self::INGREDIENT_LOW_THRESHOLD);

                if (! $state) {
                    return null;
                }

                return [
                    'item_type' => 'ingredient',
                    'name' => $item->ingredient->name,
                    'category' => $item->ingredient->category,
                    'location' => $item->warehouse?->name,
                    'quantity' => $qty,
                    'threshold' => self::INGREDIENT_LOW_THRESHOLD,
                    'stock_value_at_cost' => round($qty * (float) $item->ingredient->cost_per_unit, 2),
                    'state' => $state,
                ];
            })->filter()->values();
    }

    private function stateFor(float $quantity, int $threshold): ?string
    {
        if ($quantity <= 0) {
            return 'sold_out';
        }

        if ($quantity <= $threshold) {
            return 'low';
        }

        return null;
    }
}
