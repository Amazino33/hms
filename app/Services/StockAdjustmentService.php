<?php

namespace App\Services;

use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    /**
     * Create a pending adjustment request. This never touches stock —
     * only an approval (by someone other than the requester) does that.
     */
    public function request(array $data, int $requestedByUserId): StockAdjustment
    {
        return StockAdjustment::create([
            'item_type' => $data['item_type'],
            'product_id' => $data['item_type'] === 'product' ? $data['item_id'] : null,
            'ingredient_id' => $data['item_type'] === 'ingredient' ? $data['item_id'] : null,
            'warehouse_id' => $data['warehouse_id'],
            'quantity_change' => $data['quantity_change'],
            'reason' => $data['reason'],
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
            'requested_by' => $requestedByUserId,
        ]);
    }

    /**
     * Approve a pending adjustment, applying the stock movement and logging
     * an InventoryTransaction/IngredientTransaction of type 'adjustment'.
     *
     * Four-eyes is enforced here unconditionally: the reviewer can never be
     * the requester, regardless of role — there is no direct-apply exception
     * for managers or super_admin. On top of that, the reviewer must
     * actually hold the Update:StockAdjustment permission (manager+) —
     * "not the requester" alone previously let any peer with ViewAny
     * (bartender, storekeeper, chef) approve a colleague's request.
     *
     * @throws \Exception
     */
    public function approve(StockAdjustment $adjustment, User $reviewer): StockAdjustment
    {
        if (!$adjustment->isPending()) {
            throw new \Exception('Only pending adjustments can be approved.');
        }

        if ($adjustment->requested_by === $reviewer->id) {
            throw new \Exception('A stock adjustment cannot be approved by the same person who requested it.');
        }

        if ($reviewer->cannot('update', $adjustment)) {
            throw new \Exception('You do not have permission to approve stock adjustments.');
        }

        $reviewedByUserId = $reviewer->id;

        return DB::transaction(function () use ($adjustment, $reviewedByUserId) {
            if ($adjustment->item_type === 'product') {
                $this->applyProductAdjustment($adjustment, $reviewedByUserId);
            } else {
                $this->applyIngredientAdjustment($adjustment, $reviewedByUserId);
            }

            $adjustment->update([
                'status' => 'approved',
                'reviewed_by' => $reviewedByUserId,
                'reviewed_at' => now(),
            ]);

            return $adjustment->fresh();
        });
    }

    /**
     * Reject a pending adjustment. No stock movement ever happens for a
     * rejected request. Four-eyes applies here too — a requester cannot
     * reject (i.e. review) their own request — and, as with approve(), the
     * reviewer must hold Update:StockAdjustment (manager+), not merely be
     * someone other than the requester.
     *
     * @throws \Exception
     */
    public function reject(StockAdjustment $adjustment, User $reviewer, string $rejectionReason): StockAdjustment
    {
        if (!$adjustment->isPending()) {
            throw new \Exception('Only pending adjustments can be rejected.');
        }

        if ($adjustment->requested_by === $reviewer->id) {
            throw new \Exception('A stock adjustment cannot be reviewed by the same person who requested it.');
        }

        if ($reviewer->cannot('update', $adjustment)) {
            throw new \Exception('You do not have permission to review stock adjustments.');
        }

        $reviewedByUserId = $reviewer->id;

        $adjustment->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewedByUserId,
            'reviewed_at' => now(),
            'rejection_reason' => $rejectionReason,
        ]);

        return $adjustment->fresh();
    }

    private function applyProductAdjustment(StockAdjustment $adjustment, int $reviewedByUserId): void
    {
        $inventory = InventoryItem::query()
            ->where('product_id', $adjustment->product_id)
            ->where('warehouse_id', $adjustment->warehouse_id)
            ->lockForUpdate()
            ->first();

        $newQuantity = (float) ($inventory->quantity ?? 0) + (float) $adjustment->quantity_change;

        if ($newQuantity < 0) {
            throw new \Exception('This adjustment would reduce stock below zero.');
        }

        if ($inventory) {
            $inventory->update(['quantity' => $newQuantity]);
        } else {
            InventoryItem::create([
                'product_id' => $adjustment->product_id,
                'warehouse_id' => $adjustment->warehouse_id,
                'quantity' => $newQuantity,
            ]);
        }

        InventoryTransaction::create([
            'product_id' => $adjustment->product_id,
            'warehouse_id' => $adjustment->warehouse_id,
            'type' => 'adjustment',
            'quantity' => abs((float) $adjustment->quantity_change),
            'reference' => "stock_adjustment:{$adjustment->id}:{$adjustment->reason}",
            'user_id' => $reviewedByUserId,
        ]);
    }

    private function applyIngredientAdjustment(StockAdjustment $adjustment, int $reviewedByUserId): void
    {
        $inventory = IngredientInventoryItem::query()
            ->where('ingredient_id', $adjustment->ingredient_id)
            ->where('warehouse_id', $adjustment->warehouse_id)
            ->lockForUpdate()
            ->first();

        $newQuantity = (float) ($inventory->quantity ?? 0) + (float) $adjustment->quantity_change;

        if ($newQuantity < 0) {
            throw new \Exception('This adjustment would reduce stock below zero.');
        }

        if ($inventory) {
            $inventory->update(['quantity' => $newQuantity]);
        } else {
            IngredientInventoryItem::create([
                'ingredient_id' => $adjustment->ingredient_id,
                'warehouse_id' => $adjustment->warehouse_id,
                'quantity' => $newQuantity,
            ]);
        }

        IngredientTransaction::create([
            'ingredient_id' => $adjustment->ingredient_id,
            'warehouse_id' => $adjustment->warehouse_id,
            'type' => 'adjustment',
            'quantity' => abs((float) $adjustment->quantity_change),
            'reference' => "stock_adjustment:{$adjustment->id}:{$adjustment->reason}",
            'user_id' => $reviewedByUserId,
        ]);
    }
}
