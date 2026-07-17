<?php

namespace App\Services;

use App\Models\DamageReport;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * A pending report never touches stock — only approve() does, and only
 * through the same lock-then-decrement-then-log shape every other
 * inventory mutation in this app already uses. Damages are valued at
 * cost (cost_per_unit), deliberately distinct from shortages, which stay
 * valued at selling price via CountSessionService::unitSellingPrice().
 */
class DamageReportService
{
    /**
     * @param array{product_id?: int, ingredient_id?: int, quantity: float, note: string, photo?: string} $data
     */
    public function report(array $data, int $warehouseId, int $reportedByUserId): DamageReport
    {
        $hasProduct = ! empty($data['product_id']);
        $hasIngredient = ! empty($data['ingredient_id']);

        if ($hasProduct === $hasIngredient) {
            throw new \Exception('A damage report must be for exactly one product or one ingredient.');
        }

        if ((float) ($data['quantity'] ?? 0) <= 0) {
            throw new \Exception('Quantity must be greater than zero.');
        }

        if (trim($data['note'] ?? '') === '') {
            throw new \Exception('A note is required.');
        }

        return DamageReport::create([
            'product_id' => $data['product_id'] ?? null,
            'ingredient_id' => $data['ingredient_id'] ?? null,
            'quantity' => $data['quantity'],
            'warehouse_id' => $warehouseId,
            'reported_by' => $reportedByUserId,
            'note' => $data['note'],
            'photo' => $data['photo'] ?? null,
            'status' => 'pending',
        ]);
    }

    /**
     * Immutable once executed — approving a report that isn't pending
     * throws rather than silently re-running the write-off a second time.
     */
    public function approve(DamageReport $report, int $resolvedByUserId, ?string $resolutionNote = null): DamageReport
    {
        if (! $report->isPending()) {
            throw new \Exception('This damage report has already been resolved.');
        }

        return DB::transaction(function () use ($report, $resolvedByUserId, $resolutionNote) {
            $report = DamageReport::where('id', $report->id)->lockForUpdate()->firstOrFail();

            if (! $report->isPending()) {
                throw new \Exception('This damage report has already been resolved.');
            }

            if ($report->product_id) {
                $transaction = $this->writeOffProduct($report, $resolvedByUserId);
                $report->update([
                    'status' => 'approved',
                    'resolved_by' => $resolvedByUserId,
                    'resolved_at' => now(),
                    'resolution_note' => $resolutionNote,
                    'inventory_transaction_id' => $transaction->id,
                ]);
            } else {
                $transaction = $this->writeOffIngredient($report, $resolvedByUserId);
                $report->update([
                    'status' => 'approved',
                    'resolved_by' => $resolvedByUserId,
                    'resolved_at' => now(),
                    'resolution_note' => $resolutionNote,
                    'ingredient_transaction_id' => $transaction->id,
                ]);
            }

            activity('damage_report')
                ->performedOn($report)
                ->causedBy(\App\Models\User::find($resolvedByUserId))
                ->log('Damage report approved — stock written off');

            return $report->fresh();
        });
    }

    public function reject(DamageReport $report, int $resolvedByUserId, string $resolutionNote): DamageReport
    {
        if (trim($resolutionNote) === '') {
            throw new \Exception('A resolution note is required to reject a damage report.');
        }

        if (! $report->isPending()) {
            throw new \Exception('This damage report has already been resolved.');
        }

        return DB::transaction(function () use ($report, $resolvedByUserId, $resolutionNote) {
            $report = DamageReport::where('id', $report->id)->lockForUpdate()->firstOrFail();

            if (! $report->isPending()) {
                throw new \Exception('This damage report has already been resolved.');
            }

            $report->update([
                'status' => 'rejected',
                'resolved_by' => $resolvedByUserId,
                'resolved_at' => now(),
                'resolution_note' => $resolutionNote,
            ]);

            activity('damage_report')
                ->performedOn($report)
                ->causedBy(\App\Models\User::find($resolvedByUserId))
                ->log('Damage report rejected — no stock change');

            return $report->fresh();
        });
    }

    private function writeOffProduct(DamageReport $report, int $userId): InventoryTransaction
    {
        $inventory = InventoryItem::query()
            ->where('product_id', $report->product_id)
            ->where('warehouse_id', $report->warehouse_id)
            ->lockForUpdate()
            ->first();

        $currentStock = $inventory->quantity ?? 0;

        if ($currentStock < $report->quantity) {
            throw new \Exception("Cannot write off {$report->quantity}: only {$currentStock} on hand.");
        }

        $inventory->decrement('quantity', $report->quantity);

        $product = Product::find($report->product_id);

        return InventoryTransaction::create([
            'product_id' => $report->product_id,
            'warehouse_id' => $report->warehouse_id,
            'type' => 'damage_write_off',
            'quantity' => $report->quantity,
            'cost_per_unit' => $product?->last_cost_price,
            'reference' => "damage_report:{$report->id}",
            'user_id' => $userId,
        ]);
    }

    private function writeOffIngredient(DamageReport $report, int $userId): IngredientTransaction
    {
        $inventory = IngredientInventoryItem::query()
            ->where('ingredient_id', $report->ingredient_id)
            ->where('warehouse_id', $report->warehouse_id)
            ->lockForUpdate()
            ->first();

        $currentStock = $inventory->quantity ?? 0;

        if ($currentStock < $report->quantity) {
            throw new \Exception("Cannot write off {$report->quantity}: only {$currentStock} on hand.");
        }

        $inventory->decrement('quantity', $report->quantity);

        $ingredient = Ingredient::find($report->ingredient_id);

        return IngredientTransaction::create([
            'ingredient_id' => $report->ingredient_id,
            'warehouse_id' => $report->warehouse_id,
            'type' => 'damage_write_off',
            'quantity' => $report->quantity,
            'cost_per_unit' => $ingredient?->cost_per_unit,
            'reference' => "damage_report:{$report->id}",
            'user_id' => $userId,
        ]);
    }
}
