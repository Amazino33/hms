<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Procurement;
use App\Models\ProcurementIngredientItem;
use App\Models\ProcurementItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProcurementService
{
    public function findSimilarProducts(string $name): Collection
    {
        return Product::query()
            ->where('name', 'like', '%' . $name . '%')
            ->limit(5)
            ->get();
    }

    public function findSimilarIngredients(string $name): Collection
    {
        return Ingredient::query()
            ->where('name', 'like', '%' . $name . '%')
            ->limit(5)
            ->get();
    }

    /**
     * Commit a full procurement: header + product lines + ingredient lines.
     * Each line is either against an existing product/ingredient id, or
     * carries a 'new_product'/'new_ingredient' array to create one inline.
     * Stock is applied immediately (same accepted exception as
     * QuickInventoryUpdate — goods receipt bypasses the four-eyes
     * StockAdjustmentService review).
     */
    public function commit(array $header, array $productLines, array $ingredientLines, int $userId): Procurement
    {
        return DB::transaction(function () use ($header, $productLines, $ingredientLines, $userId) {
            $year = now()->year;
            $seq = Procurement::whereYear('created_at', $year)->lockForUpdate()->count() + 1;

            $procurement = Procurement::create([
                'reference' => sprintf('PRC-%d-%04d', $year, $seq),
                'location_id' => $header['location_id'],
                'supplier_name' => $header['supplier_name'] ?? null,
                'purchased_at' => $header['purchased_at'],
                'recorded_by' => $userId,
                'total_cost' => 0,
            ]);

            $total = 0.0;

            foreach ($productLines as $line) {
                $total += $this->commitProductLine($procurement, $line, $userId);
            }

            foreach ($ingredientLines as $line) {
                $total += $this->commitIngredientLine($procurement, $line, $userId);
            }

            $procurement->update(['total_cost' => $total]);

            return $procurement->fresh(['items', 'ingredientItems']);
        });
    }

    private function commitProductLine(Procurement $procurement, array $line, int $userId): float
    {
        $product = isset($line['product_id'])
            ? Product::findOrFail($line['product_id'])
            : $this->createProduct($line['new_product'], $userId);

        $baseQty = PackConversionService::toBaseQty(
            (float) $line['entered_qty'],
            $line['entered_unit'],
            $product->units_per_purchase_unit,
        );
        $unitCost = PackConversionService::unitCost((float) $line['line_total_cost'], $baseQty);

        $inventory = InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $procurement->location_id)
            ->lockForUpdate()
            ->first();

        if ($inventory) {
            $inventory->increment('quantity', $baseQty);
        } else {
            InventoryItem::create([
                'product_id' => $product->id,
                'warehouse_id' => $procurement->location_id,
                'quantity' => $baseQty,
            ]);
        }

        $transaction = InventoryTransaction::create([
            'product_id' => $product->id,
            'warehouse_id' => $procurement->location_id,
            'type' => 'purchase',
            'quantity' => $baseQty,
            'cost_per_unit' => $unitCost,
            'reference' => "procurement:{$procurement->id}",
            'user_id' => $userId,
        ]);

        ProcurementItem::create([
            'procurement_id' => $procurement->id,
            'product_id' => $product->id,
            'entered_qty' => $line['entered_qty'],
            'entered_unit' => $line['entered_unit'],
            'units_per_purchase_unit_snapshot' => $product->units_per_purchase_unit,
            'base_qty' => $baseQty,
            'line_total_cost' => $line['line_total_cost'],
            'unit_cost' => $unitCost,
            'inventory_transaction_id' => $transaction->id,
        ]);

        $product->update([
            'last_cost_price' => $unitCost,
            'cost_price' => $unitCost,
        ]);

        return (float) $line['line_total_cost'];
    }

    private function commitIngredientLine(Procurement $procurement, array $line, int $userId): float
    {
        $ingredient = isset($line['ingredient_id'])
            ? Ingredient::findOrFail($line['ingredient_id'])
            : $this->createIngredient($line['new_ingredient'], $userId);

        $baseQty = PackConversionService::toBaseQty(
            (float) $line['entered_qty'],
            $line['entered_unit'],
            $ingredient->units_per_purchase_unit,
        );
        $unitCost = PackConversionService::unitCost((float) $line['line_total_cost'], $baseQty);

        $inventory = IngredientInventoryItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('warehouse_id', $procurement->location_id)
            ->lockForUpdate()
            ->first();

        if ($inventory) {
            $inventory->increment('quantity', $baseQty);
        } else {
            IngredientInventoryItem::create([
                'ingredient_id' => $ingredient->id,
                'warehouse_id' => $procurement->location_id,
                'quantity' => $baseQty,
            ]);
        }

        $transaction = IngredientTransaction::create([
            'ingredient_id' => $ingredient->id,
            'warehouse_id' => $procurement->location_id,
            'type' => 'purchase',
            'quantity' => $baseQty,
            'cost_per_unit' => $unitCost,
            'reference' => "procurement:{$procurement->id}",
            'user_id' => $userId,
        ]);

        ProcurementIngredientItem::create([
            'procurement_id' => $procurement->id,
            'ingredient_id' => $ingredient->id,
            'entered_qty' => $line['entered_qty'],
            'entered_unit' => $line['entered_unit'],
            'units_per_purchase_unit_snapshot' => $ingredient->units_per_purchase_unit,
            'base_qty' => $baseQty,
            'line_total_cost' => $line['line_total_cost'],
            'unit_cost' => $unitCost,
            'ingredient_transaction_id' => $transaction->id,
        ]);

        $ingredient->update(['cost_per_unit' => $unitCost]);

        return (float) $line['line_total_cost'];
    }

    private function createProduct(array $data, int $userId): Product
    {
        if (empty($data['name']) || empty($data['category_id'])) {
            throw new InvalidArgumentException('A new product requires a name and category.');
        }

        return Product::create([
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'category_id' => $data['category_id'],
            'base_unit' => $data['base_unit'] ?? 'bottle',
            'purchase_unit_name' => $data['purchase_unit_name'] ?? null,
            'units_per_purchase_unit' => $data['units_per_purchase_unit'] ?? null,
            'price' => $data['price'] ?? 0,
            'cost_price' => 0,
            'is_active' => true,
            'created_by_staff' => true,
            'created_by' => $userId,
        ]);
    }

    private function createIngredient(array $data, int $userId): Ingredient
    {
        if (empty($data['name'])) {
            throw new InvalidArgumentException('A new ingredient requires a name.');
        }

        return Ingredient::create([
            'name' => $data['name'],
            'sku' => $data['sku'] ?? Str::upper(Str::slug($data['name'], '')),
            'unit_name' => $data['unit_name'] ?? 'kg',
            'quantity' => 0,
            'cost_per_unit' => 0,
            'category' => $data['category'] ?? 'Uncategorized',
            'purchase_unit_name' => $data['purchase_unit_name'] ?? null,
            'units_per_purchase_unit' => $data['units_per_purchase_unit'] ?? null,
            'created_by_staff' => true,
            'created_by' => $userId,
        ]);
    }
}
