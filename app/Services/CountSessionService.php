<?php

namespace App\Services;

use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\CountSessionSubCount;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\WareHouse;
use Illuminate\Support\Facades\DB;

class CountSessionService
{
    private const ROLE_FOR_HANDOVER = [
        'bar_handover' => 'bartender',
        'kitchen_handover' => 'chef',
    ];

    /**
     * Open a new count session and snapshot every product/ingredient
     * currently tracked at this warehouse as the blind "expected" baseline.
     * Nothing about this baseline is ever exposed while status = 'counting'.
     */
    public function openSession(
        string $type,
        int $warehouseId,
        int $openedByUserId,
        ?int $outgoingUserId = null,
        ?int $incomingUserId = null,
        ?string $notes = null,
        bool $isClosing = false,
    ): CountSession {
        $isHandover = in_array($type, ['bar_handover', 'kitchen_handover'], true);

        if ($isClosing && !$isHandover) {
            throw new \Exception('Only a bar/kitchen count can be a closing count.');
        }

        if ($isHandover && !$incomingUserId) {
            throw new \Exception($isClosing
                ? 'A closing count requires a second person to confirm it.'
                : 'A handover count requires an incoming user.');
        }

        if ($isHandover && !$outgoingUserId) {
            // No outgoing custodian named — only legitimate if this is truly
            // the first shift of the day (no active shift of that role yet).
            $role = self::ROLE_FOR_HANDOVER[$type];
            if (Shift::query()->ofType($role)->activeNonStale($role)->exists()) {
                throw new \Exception('An outgoing user is required — a shift for this role is already active.');
            }
        }

        return DB::transaction(function () use ($type, $warehouseId, $openedByUserId, $outgoingUserId, $incomingUserId, $notes, $isClosing) {
            $session = CountSession::create([
                'type' => $type,
                'warehouse_id' => $warehouseId,
                'status' => 'counting',
                'opened_by' => $openedByUserId,
                'opened_at' => now(),
                'outgoing_user_id' => $outgoingUserId,
                'incoming_user_id' => $incomingUserId,
                'is_closing' => $isClosing,
                'notes' => $notes,
            ]);

            // Snapshotted at open time, not resolved live from the warehouse
            // each time — a label edited mid-count must not reshuffle an
            // already-open session's slots.
            $subLocationLabels = WareHouse::findOrFail($warehouseId)->subLocationLabels();

            if ($type === 'kitchen_handover') {
                $rows = IngredientInventoryItem::where('warehouse_id', $warehouseId)->get();
                foreach ($rows as $row) {
                    $item = CountSessionItem::create([
                        'count_session_id' => $session->id,
                        'item_type' => 'ingredient',
                        'ingredient_id' => $row->ingredient_id,
                        'expected_quantity_at_open' => $row->quantity,
                    ]);
                    $this->seedSubLocationSlots($item, $subLocationLabels);
                }
            } else {
                $rows = InventoryItem::where('warehouse_id', $warehouseId)->get();
                foreach ($rows as $row) {
                    $item = CountSessionItem::create([
                        'count_session_id' => $session->id,
                        'item_type' => 'product',
                        'product_id' => $row->product_id,
                        'expected_quantity_at_open' => $row->quantity,
                    ]);
                    $this->seedSubLocationSlots($item, $subLocationLabels);
                }
            }

            return $session;
        });
    }

    /**
     * Every item gets exactly the warehouse's 3 fixed sub-location slots,
     * pre-created at zero — counting only ever updates these rows, it never
     * creates new arbitrary sub-locations.
     */
    private function seedSubLocationSlots(CountSessionItem $item, array $labels): void
    {
        foreach ($labels as $label) {
            CountSessionSubCount::create([
                'count_session_item_id' => $item->id,
                'sub_location' => $label,
                'quantity' => 0,
            ]);
        }
    }

    /**
     * Record (or overwrite) the counted quantities for an item's fixed
     * sub-location slots. Blind by construction — this never reads or
     * returns expected_quantity_at_open. Blank/omitted quantities count as
     * zero. Only the 3 slots seeded at session-open time for this item can
     * be written — an unrecognized sub-location name is rejected rather
     * than silently creating a new, unbounded entry.
     *
     * @param array<string, float|string|null> $quantitiesBySubLocation
     */
    public function recordCount(CountSessionItem $item, array $quantitiesBySubLocation): CountSessionItem
    {
        if (!$item->session->isDraft()) {
            throw new \Exception('Counts can only be recorded while the session is still open.');
        }

        $validLocations = $item->subCounts()->pluck('sub_location')->all();

        foreach ($quantitiesBySubLocation as $subLocation => $quantity) {
            if (!in_array($subLocation, $validLocations, true)) {
                throw new \Exception("'{$subLocation}' is not a valid sub-location for this item.");
            }

            CountSessionSubCount::where('count_session_item_id', $item->id)
                ->where('sub_location', $subLocation)
                ->update(['quantity' => $quantity === null || $quantity === '' ? 0 : (float) $quantity]);
        }

        $item->update([
            'counted_quantity' => $item->subCounts()->sum('quantity'),
        ]);

        return $item->fresh();
    }

    public function confirmOutgoing(CountSession $session, int $userId): CountSession
    {
        if ($session->outgoing_user_id !== $userId) {
            throw new \Exception('Only the outgoing custodian can give this confirmation.');
        }

        $session->update(['confirmed_by_outgoing_at' => now()]);

        return $session->fresh();
    }

    public function confirmIncoming(CountSession $session, int $userId): CountSession
    {
        if ($session->incoming_user_id !== $userId) {
            throw new \Exception('Only the incoming custodian can give this confirmation.');
        }

        $session->update(['confirmed_by_incoming_at' => now()]);

        return $session->fresh();
    }

    /**
     * Close counting and compute each item's adjusted-expected quantity
     * against LIVE stock right now — since nothing outside the transaction
     * pipeline can move stock, the current InventoryItem/IngredientInventoryItem
     * figure already equals "expected at open, adjusted for everything that
     * happened during counting."
     */
    public function submitForReview(CountSession $session): CountSession
    {
        if (!$session->isDraft()) {
            throw new \Exception('Only a session that is still counting can be submitted for review.');
        }

        if ($session->isHandover() && $session->outgoing_user_id && !$session->confirmed_by_outgoing_at) {
            throw new \Exception('Both the outgoing and incoming custodian must confirm the count before it can be submitted.');
        }

        if ($session->isHandover() && !$session->confirmed_by_incoming_at) {
            throw new \Exception('Both the outgoing and incoming custodian must confirm the count before it can be submitted.');
        }

        return DB::transaction(function () use ($session) {
            foreach ($session->items as $item) {
                $currentQty = $item->item_type === 'product'
                    ? (float) (InventoryItem::where('product_id', $item->product_id)->where('warehouse_id', $session->warehouse_id)->value('quantity') ?? 0)
                    : (float) (IngredientInventoryItem::where('ingredient_id', $item->ingredient_id)->where('warehouse_id', $session->warehouse_id)->value('quantity') ?? 0);

                $counted = (float) ($item->counted_quantity ?? 0);

                $item->update([
                    'adjusted_expected_quantity' => $currentQty,
                    'variance' => $counted - $currentQty,
                ]);
            }

            $session->update([
                'status' => 'pending_review',
                'submitted_for_review_at' => now(),
            ]);

            return $session->fresh();
        });
    }

    /**
     * Manager decision on one item's variance. Both 'true_up' and
     * 'accountability' correct the book to match the physical count;
     * 'accountability' additionally charges the accountable custodian at
     * valuation (selling price for products, last purchase price for
     * ingredients). 'ignored' leaves the book untouched.
     */
    public function reviewItem(CountSessionItem $item, int $reviewerId, string $decision, ?string $notes = null): CountSessionItem
    {
        if (!in_array($decision, ['true_up', 'accountability', 'ignored'], true)) {
            throw new \Exception('Invalid decision.');
        }

        if (!$item->session->isPendingReview()) {
            throw new \Exception('This session is not awaiting review.');
        }

        $variance = (float) ($item->variance ?? 0);

        return DB::transaction(function () use ($item, $reviewerId, $decision, $notes, $variance) {
            if ($decision !== 'ignored' && abs($variance) > 0.0001) {
                $this->trueUpStock($item, $variance, $reviewerId);
            }

            if ($decision === 'accountability' && $variance < 0) {
                $this->chargeAccountability($item, abs($variance), $reviewerId);
            }

            $item->update(['decision' => $decision, 'decision_notes' => $notes]);

            return $item->fresh();
        });
    }

    /**
     * Finalize the session once every non-zero-variance item has a
     * decision. For a handover, this is literally the shift boundary — the
     * outgoing custodian's shift ends and the incoming custodian's shift
     * starts in the same transaction, with no separate step to forget.
     */
    public function finalizeReview(CountSession $session, int $reviewedByUserId): CountSession
    {
        if (!$session->isPendingReview()) {
            throw new \Exception('Only a session pending review can be finalized.');
        }

        $undecided = $session->items()
            ->whereNull('decision')
            ->where(function ($q) {
                $q->where('variance', '>', 0.0001)->orWhere('variance', '<', -0.0001);
            })
            ->exists();

        if ($undecided) {
            throw new \Exception('Every item with a variance must have a decision before this session can be finalized.');
        }

        return DB::transaction(function () use ($session, $reviewedByUserId) {
            $session->update([
                'status' => 'reviewed',
                'reviewed_by' => $reviewedByUserId,
                'reviewed_at' => now(),
            ]);

            if ($session->isHandover()) {
                (new BartenderChefShiftService())->applyHandoverShiftBoundary($session->fresh());
            }

            return $session->fresh();
        });
    }

    private function trueUpStock(CountSessionItem $item, float $variance, int $reviewerId): void
    {
        $warehouseId = $item->session->warehouse_id;

        if ($item->item_type === 'product') {
            InventoryItem::updateOrCreate(
                ['product_id' => $item->product_id, 'warehouse_id' => $warehouseId],
                []
            )->update(['quantity' => $item->counted_quantity]);

            InventoryTransaction::create([
                'product_id' => $item->product_id,
                'warehouse_id' => $warehouseId,
                'type' => 'adjustment',
                'quantity' => abs($variance),
                'reference' => "count_session:{$item->count_session_id}",
                'user_id' => $reviewerId,
            ]);
        } else {
            IngredientInventoryItem::updateOrCreate(
                ['ingredient_id' => $item->ingredient_id, 'warehouse_id' => $warehouseId],
                []
            )->update(['quantity' => $item->counted_quantity]);

            IngredientTransaction::create([
                'ingredient_id' => $item->ingredient_id,
                'warehouse_id' => $warehouseId,
                'type' => 'adjustment',
                'quantity' => abs($variance),
                'reference' => "count_session:{$item->count_session_id}",
                'user_id' => $reviewerId,
            ]);
        }
    }

    private function chargeAccountability(CountSessionItem $item, float $shortfallQuantity, int $reviewerId): void
    {
        $accountableUserId = $item->session->accountableUserId();

        if (!$accountableUserId) {
            throw new \Exception('There is no accountable user recorded on this session to charge.');
        }

        $unitValue = $item->item_type === 'product'
            ? (float) (Product::find($item->product_id)?->price ?? 0)
            : $this->lastPurchasePrice($item->ingredient_id);

        StaffDebt::create([
            'user_id' => $accountableUserId,
            'amount' => round($shortfallQuantity * $unitValue, 2),
            'reason' => 'count_session_shortfall',
            'notes' => "Count session #{$item->count_session_id}, item #{$item->id} ({$item->itemName()}): "
                . "{$shortfallQuantity} short at " . number_format($unitValue, 2) . ' per unit.',
            'created_by' => $reviewerId,
        ]);
    }

    private function lastPurchasePrice(int $ingredientId): float
    {
        return (float) (IngredientTransaction::where('ingredient_id', $ingredientId)
            ->where('type', 'purchase')
            ->whereNotNull('cost_per_unit')
            ->latest('id')
            ->value('cost_per_unit') ?? 0);
    }
}
