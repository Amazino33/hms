<?php

namespace App\Services;

use App\Models\CountSessionItem;
use App\Models\FridgeRestockMark;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\WareHouse;
use Illuminate\Support\Collection;

/**
 * A lightweight, deliberately approximate by-product of count sessions
 * capturing per-sub-location figures: since every count already records how
 * much of a product was in the Fridge, we can estimate current cold stock
 * between counts without any new hardware or process. This is guidance for
 * the bartender only — it never blocks a sale and never touches the
 * InventoryTransaction ledger.
 *
 * Assumption made explicit: bar sales are assumed to be served from the
 * fridge (a reasonable default for drinks), so every sale since the last
 * baseline decays the estimate by the same amount. Moving stock from floor
 * to fridge does not change total warehouse stock, so the "restocked"
 * marker is tracked separately from the transaction ledger entirely.
 */
class FridgeStockEstimateService
{
    public const FRIDGE_LABEL = 'Fridge';

    /**
     * Estimated units of this product currently cold, or null if there is no
     * baseline yet (never reviewed a count with a Fridge figure for it, and
     * never manually marked restocked) — callers must treat null as "unknown",
     * not zero.
     */
    public function estimate(Product $product, WareHouse $warehouse): ?float
    {
        $baseline = $this->latestBaseline($product, $warehouse);

        if ($baseline === null) {
            return null;
        }

        // >= rather than > : timestamp columns only have second-level
        // precision, so a baseline and a sale landing in the same clock
        // second must still count the sale as "since" — the alternative
        // (>) would silently drop it and overstate the estimate.
        $soldSince = (float) InventoryTransaction::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('type', 'sale')
            ->where('created_at', '>=', $baseline['at'])
            ->sum('quantity');

        return max(0.0, $baseline['quantity'] - $soldSince);
    }

    /**
     * One-tap "topped up to par" — the only supported quick action. Deducting
     * from the floor isn't tracked here (no transaction, no sub-location
     * mutation) since total warehouse stock hasn't changed; this only resets
     * this product's fridge ESTIMATE to its configured par level, timestamped
     * now so future sales decay it from there.
     *
     * @throws \Exception
     */
    public function markRestockedToPar(Product $product, WareHouse $warehouse, int $markedByUserId): FridgeRestockMark
    {
        if ($product->fridge_par === null) {
            throw new \Exception('This product has no fridge par level set — nothing to restock to.');
        }

        return FridgeRestockMark::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['marked_quantity' => $product->fridge_par, 'marked_at' => now(), 'marked_by' => $markedByUserId]
        );
    }

    /**
     * Products with a configured par whose estimate is currently below it.
     * Products with no par never appear here (no par = no alert, ever), and
     * products with no baseline yet (estimate null) are excluded too — an
     * unknown estimate is not the same as a low one.
     */
    public function belowParProducts(WareHouse $warehouse): Collection
    {
        return Product::query()
            ->where('is_active', true)
            ->whereNotNull('fridge_par')
            ->get()
            ->map(function (Product $product) use ($warehouse) {
                return [
                    'product' => $product,
                    'estimate' => $this->estimate($product, $warehouse),
                    'par' => (float) $product->fridge_par,
                ];
            })
            ->filter(fn (array $row) => $row['estimate'] !== null && $row['estimate'] < $row['par'])
            ->sortBy('estimate')
            ->values();
    }

    /**
     * @return array{quantity: float, at: \Illuminate\Support\Carbon}|null
     */
    private function latestBaseline(Product $product, WareHouse $warehouse): ?array
    {
        $reviewed = $this->latestReviewedCountBaseline($product, $warehouse);
        $restocked = $this->latestRestockMark($product, $warehouse);

        if ($restocked === null) {
            return $reviewed;
        }

        if ($reviewed === null) {
            return $restocked;
        }

        // Timestamp columns only have second-level precision, so a restock
        // marked in the same clock second as a review must still win — it
        // represents "just happened", always at least as fresh.
        return $restocked['at']->greaterThanOrEqualTo($reviewed['at']) ? $restocked : $reviewed;
    }

    private function latestReviewedCountBaseline(Product $product, WareHouse $warehouse): ?array
    {
        $item = CountSessionItem::query()
            ->where('item_type', 'product')
            ->where('product_id', $product->id)
            ->whereHas('session', function ($query) use ($warehouse) {
                $query->where('warehouse_id', $warehouse->id)->where('status', 'reviewed');
            })
            ->with('session')
            ->get()
            ->sortByDesc(fn (CountSessionItem $item) => $item->session->reviewed_at)
            ->first();

        if (!$item) {
            return null;
        }

        $fridgeQuantity = $item->subCounts()
            ->where('sub_location', self::FRIDGE_LABEL)
            ->value('quantity');

        if ($fridgeQuantity === null) {
            return null;
        }

        return ['quantity' => (float) $fridgeQuantity, 'at' => $item->session->reviewed_at];
    }

    private function latestRestockMark(Product $product, WareHouse $warehouse): ?array
    {
        $mark = FridgeRestockMark::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        if (!$mark) {
            return null;
        }

        return ['quantity' => (float) $mark->marked_quantity, 'at' => $mark->marked_at];
    }
}
