<?php

namespace App\Services;

use App\Models\CountSession;
use App\Models\IngredientTransaction;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Models\StockAdjustment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The forensic layer behind the Sales Trace page: "who sold how much of
 * what, on which day, and does that agree with what actually left the
 * warehouse".
 *
 * Exists because a count-session variance is computed in
 * CountSessionService::closeSessionAndReconcile() as
 * `counted - live InventoryItem.quantity` — it is never a derived
 * "opening + in - out" figure. So when a variance appears, nothing in the
 * system explains why: you get a number and no ledger. This rebuilds the
 * ledger.
 *
 * ---------------------------------------------------------------------
 * Why the direction map lives here and nowhere else
 * ---------------------------------------------------------------------
 * `inventory_transactions.quantity` and `ingredient_transactions.quantity`
 * store a MAGNITUDE, never a signed value, so `SUM(quantity)` is always
 * wrong. Direction has to be recovered per row, and two types cannot
 * recover it from the row alone:
 *
 *  - 'transfer' writes the SAME type string for both legs
 *    (StockTransferService). Only the reference suffix ":out" / ":in"
 *    separates them — summed naively a transfer nets to double, not zero.
 *
 *  - 'adjustment' is overloaded across four unrelated writers, every one
 *    of which stores abs():
 *      * "stock_adjustment:{id}:{reason}" — sign IS recoverable, by
 *        joining back to stock_adjustments.quantity_change.
 *      * "count_session:{id}" — the true-up that CLOSES a variance.
 *        Excluded from the movements explaining the next one, or every
 *        trace double-counts its own correction.
 *      * "handover_discrepancy:{id}:recount" and "bulk_stock_set:{date}"
 *        — sign is genuinely UNRECOVERABLE; nothing signed is persisted
 *        to join back to. Reported as direction-unknown rather than
 *        guessed, because a guess here silently moves the "unexplained"
 *        figure this whole page exists to compute.
 */
class StockTraceService
{
    /** Types that always add stock, whatever the reference says. */
    private const INBOUND = ['purchase', 'return', 'opening_balance', 'transfer_reversal_in'];

    /** Types that always remove stock. 'sale' is products, 'usage' is ingredients. */
    private const OUTBOUND = ['sale', 'usage', 'damage_write_off'];

    /**
     * Classify a reference string into the operation that wrote it, so the
     * ladder can label rows in language a manager recognises instead of
     * showing raw enum values.
     */
    public function referenceKind(?string $reference): string
    {
        $reference ??= '';

        return match (true) {
            str_starts_with($reference, 'order:') => 'sale',
            str_starts_with($reference, 'transfer:') => 'transfer',
            str_starts_with($reference, 'procurement:') => 'procurement',
            str_starts_with($reference, 'stock_adjustment:') => 'stock_adjustment',
            str_starts_with($reference, 'count_session:') => 'count_true_up',
            str_starts_with($reference, 'handover_discrepancy:') => 'recount_true_up',
            str_starts_with($reference, 'discrepancy:') => 'transfer_reversal',
            str_starts_with($reference, 'damage_report:') => 'damage',
            str_starts_with($reference, 'bulk_stock_set:') => 'bulk_stock_set',
            str_starts_with($reference, 'booking:') => 'room_charge',
            str_starts_with($reference, 'opening_stock') => 'opening_stock',
            str_starts_with($reference, 'bulk_import_row_') => 'import',
            default => 'other',
        };
    }

    /**
     * Human label for a movement row.
     */
    public function movementLabel(string $kind): string
    {
        return match ($kind) {
            'sale' => 'Sold',
            'transfer' => 'Transfer',
            'procurement' => 'Procurement received',
            'stock_adjustment' => 'Stock adjustment',
            'count_true_up' => 'Count true-up',
            'recount_true_up' => 'Discrepancy recount true-up',
            'transfer_reversal' => 'Transfer reversal',
            'damage' => 'Damage write-off',
            'bulk_stock_set' => 'Bulk stock set',
            'room_charge' => 'Room charge',
            'opening_stock' => 'Opening stock',
            'import' => 'Stock import',
            default => 'Other movement',
        };
    }

    /**
     * Signed quantity for one transaction row, or null when the direction
     * genuinely was not recorded. Per the class docblock, null is a real
     * answer here — not a failure to try.
     *
     * @param  array<int, float>  $adjustmentChanges  stock_adjustments.id => quantity_change
     */
    public function signedQuantity(object $transaction, array $adjustmentChanges = []): ?float
    {
        $quantity = abs((float) $transaction->quantity);
        $reference = (string) ($transaction->reference ?? '');

        if (in_array($transaction->type, self::INBOUND, true)) {
            return $quantity;
        }

        if (in_array($transaction->type, self::OUTBOUND, true)) {
            return -$quantity;
        }

        if ($transaction->type === 'transfer') {
            return match (true) {
                str_ends_with($reference, ':out') => -$quantity,
                str_ends_with($reference, ':in') => $quantity,
                default => null,
            };
        }

        if ($transaction->type === 'adjustment' && str_starts_with($reference, 'stock_adjustment:')) {
            $adjustmentId = (int) (explode(':', $reference)[1] ?? 0);
            $change = $adjustmentChanges[$adjustmentId] ?? null;

            if ($change === null) {
                return null;
            }

            return $change < 0 ? -$quantity : $quantity;
        }

        // count_session / handover_discrepancy recount / bulk_stock_set.
        return null;
    }

    /**
     * The window a count session actually measures: from the moment stock
     * was last trued up at this warehouse, to the moment this session
     * froze its expected figure.
     *
     * This is the reason the page offers a session picker at all. A
     * handover routinely runs 6pm to 7am, and BusinessDay closes at 9am
     * WAT — so any calendar range typed in by hand both includes sales the
     * previous count already absorbed AND misses sales after it.
     *
     * @return array{from: ?CarbonInterface, to: CarbonInterface, previous: ?CountSession}
     */
    public function windowForCountSession(CountSession $session): array
    {
        $to = $session->submitted_for_review_at
            ?? $session->reviewed_at
            ?? $session->updated_at;

        $previous = CountSession::query()
            ->where('warehouse_id', $session->warehouse_id)
            ->where('id', '!=', $session->id)
            ->where('status', 'reviewed')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '<', $to)
            ->orderByDesc('reviewed_at')
            ->first();

        return [
            'from' => $previous?->reviewed_at,
            'to' => $to,
            'previous' => $previous,
        ];
    }

    /**
     * Per day / per item / per waiter sales, with the billed figure and the
     * stock figure side by side.
     *
     * "Billed" is OrderItem — what the customer was charged. "Deducted" is
     * the matching InventoryTransaction type='sale', joined on product_id
     * plus the same "order:{id}" reference InventoryService writes. Those
     * two should agree line for line; where they do not, stock left
     * without being billed or vice versa, and that gap is invisible on
     * every other page in the system.
     *
     * Menu items have no product deduction at all — a dish moves
     * ingredients, not products. Rather than show a meaningless zero in
     * the deducted column they carry a recipe_status:
     *   - 'no_recipe' : nothing to deduct by design (service/untracked item)
     *   - 'recorded'  : every recipe ingredient shows a usage row on the order
     *   - 'partial'   : some recipe ingredients moved, some did not
     *   - 'missing'   : the item has a recipe but no ingredient moved at all
     * Presence is checked rather than quantity attributed on purpose: one
     * order can plate two dishes sharing an ingredient and (per
     * RevenueReportService's docblock) there is no unambiguous way to
     * split a shared ingredient movement back across dishes.
     *
     * @param  array{waiter_id?: int, product_id?: int, menu_item_id?: int, category_id?: int, item_type?: string}  $filters
     */
    public function salesPivot(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        $items = OrderItem::query()
            ->with(['order.user', 'product.category', 'menuItem.category', 'menuItem.recipes'])
            ->whereHas('order', function ($query) use ($from, $to, $filters) {
                $query->whereBetween('created_at', [$from, $to])
                    ->where('is_return', false)
                    ->where('status', '!=', 'cancelled');

                if (! empty($filters['waiter_id'])) {
                    $query->where('user_id', $filters['waiter_id']);
                }
            })
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(! empty($filters['menu_item_id']), fn ($q) => $q->where('menu_item_id', $filters['menu_item_id']))
            ->when(! empty($filters['item_type']), fn ($q) => $q->where('item_type', $filters['item_type']))
            ->get();

        if ($items->isEmpty()) {
            return collect();
        }

        $deducted = $this->deductedQuantityLookup($items);
        $ingredientsSeen = $this->orderIngredientLookup($items);

        $rows = $items->map(function (OrderItem $item) use ($deducted, $ingredientsSeen) {
            $product = $item->product;
            $menuItem = $item->menuItem;
            $category = $product?->category ?? $menuItem?->category;
            $isProduct = $item->item_type === 'product' && $item->product_id;

            return [
                'date' => $item->order?->created_at?->toDateString(),
                'item_key' => $isProduct ? "product_{$item->product_id}" : ($menuItem ? "menu_{$menuItem->id}" : 'unknown'),
                'item_name' => $item->product_name ?? $product?->name ?? $menuItem?->name ?? 'Unknown',
                'item_type' => $isProduct ? 'product' : 'menu_item',
                'category_id' => $category?->id,
                'category_name' => $category?->name ?? 'Uncategorized',
                'waiter_id' => $item->order?->user_id,
                'waiter_name' => $item->order?->user?->name ?? 'Unknown',
                'order_id' => $item->order_id,
                'billed_quantity' => (float) $item->quantity,
                'revenue' => (float) $item->subtotal,
                'deducted_quantity' => $isProduct
                    ? ($deducted["{$item->product_id}:{$item->order_id}"] ?? 0.0)
                    : null,
                'recipe_status' => $isProduct
                    ? null
                    : $this->recipeStatus($menuItem, $ingredientsSeen[$item->order_id] ?? []),
            ];
        });

        if (! empty($filters['category_id'])) {
            $rows = $rows->where('category_id', (int) $filters['category_id']);
        }

        return $this->aggregate($rows);
    }

    /**
     * Collapse raw order lines into one row per (day, item, waiter). Two
     * lines of the same product on one order, or across several orders on
     * the same day by the same waiter, become one row — otherwise the
     * billed-vs-deducted comparison double-reports the deduction, which is
     * keyed per product+order and not per line.
     */
    private function aggregate(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn ($row) => $row['date'].'|'.$row['item_key'].'|'.$row['waiter_id'])
            ->map(function (Collection $group) {
                $first = $group->first();
                $isProduct = $first['item_type'] === 'product';

                // Deduction is recorded once per product per order, so sum
                // across the distinct orders in this group, never across
                // the individual lines.
                $deducted = $isProduct
                    ? (float) $group->unique('order_id')->sum('deducted_quantity')
                    : null;

                $billed = (float) $group->sum('billed_quantity');

                return [
                    'date' => $first['date'],
                    'item_key' => $first['item_key'],
                    'item_name' => $first['item_name'],
                    'item_type' => $first['item_type'],
                    'category_name' => $first['category_name'],
                    'category_id' => $first['category_id'],
                    'waiter_id' => $first['waiter_id'],
                    'waiter_name' => $first['waiter_name'],
                    'billed_quantity' => $billed,
                    'deducted_quantity' => $deducted,
                    'mismatch' => $isProduct ? round($deducted - $billed, 2) : null,
                    'recipe_status' => $isProduct
                        ? null
                        : $this->worstRecipeStatus($group->pluck('recipe_status')->all()),
                    'revenue' => (float) $group->sum('revenue'),
                    'order_count' => $group->unique('order_id')->count(),
                ];
            })
            ->sortBy([['date', 'asc'], ['item_name', 'asc'], ['waiter_name', 'asc']])
            ->values();
    }

    /**
     * Worst case wins when one waiter sold the same dish several times in a
     * day — one missing deduction matters more than three good ones, and
     * averaging it away would defeat the point.
     *
     * @param  array<int, ?string>  $statuses
     */
    private function worstRecipeStatus(array $statuses): string
    {
        foreach (['missing', 'partial', 'recorded'] as $rank) {
            if (in_array($rank, $statuses, true)) {
                return $rank;
            }
        }

        return 'no_recipe';
    }

    /**
     * @param  array<int, int>  $ingredientIdsSeen
     */
    private function recipeStatus(?MenuItem $menuItem, array $ingredientIdsSeen): string
    {
        $required = $menuItem?->recipes->pluck('ingredient_id')->filter()->unique()->values()->all() ?? [];

        if ($required === []) {
            return 'no_recipe';
        }

        $found = array_intersect($required, $ingredientIdsSeen);

        return match (true) {
            count($found) === count($required) => 'recorded',
            count($found) > 0 => 'partial',
            default => 'missing',
        };
    }

    /**
     * Every stock movement for one item at one warehouse in a window,
     * direction-resolved and grouped by what caused it.
     *
     * The count true-up for `$excludeCountSessionId` is dropped: that row
     * IS the correction this trace is trying to explain, so counting it as
     * a movement would net the variance to zero and make every trace look
     * perfectly reconciled.
     *
     * @return array{rows: Collection, net: float, unknown: Collection}
     */
    public function movements(
        int $warehouseId,
        string $itemType,
        int $itemId,
        ?CarbonInterface $from,
        CarbonInterface $to,
        ?int $excludeCountSessionId = null,
    ): array {
        $query = $itemType === 'product'
            ? InventoryTransaction::query()->where('product_id', $itemId)
            : IngredientTransaction::query()->where('ingredient_id', $itemId);

        $transactions = $query
            ->where('warehouse_id', $warehouseId)
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->with('user')
            ->get();

        if ($excludeCountSessionId !== null) {
            $transactions = $transactions->reject(
                fn ($t) => (string) $t->reference === "count_session:{$excludeCountSessionId}"
            );
        }

        $adjustmentChanges = $this->adjustmentChangeLookup($transactions);

        $rows = $transactions->map(function ($transaction) use ($adjustmentChanges) {
            $kind = $this->referenceKind($transaction->reference);
            $signed = $this->signedQuantity($transaction, $adjustmentChanges);

            return [
                'id' => $transaction->id,
                'at' => $transaction->created_at,
                'type' => $transaction->type,
                'kind' => $kind,
                'label' => $this->movementLabel($kind),
                'reference' => $transaction->reference,
                'quantity' => abs((float) $transaction->quantity),
                'signed_quantity' => $signed,
                'direction_known' => $signed !== null,
                'user_name' => $transaction->user?->name,
            ];
        })->values();

        return [
            'rows' => $rows,
            'net' => (float) $rows->whereNotNull('signed_quantity')->sum('signed_quantity'),
            'unknown' => $rows->where('direction_known', false)->values(),
        ];
    }

    /**
     * The full reconciliation for one counted item: what the last count
     * left behind, everything that moved since, what that implies, and how
     * far the physical count actually landed from it.
     *
     * `unexplained` is the number that matters. It is NOT the session's own
     * variance — the session compares against live system stock, whereas
     * this compares against a figure rebuilt from the movement ledger. When
     * the two disagree, stock changed without a transaction being written.
     *
     * @return array{
     *     window: array{from: ?CarbonInterface, to: CarbonInterface, previous: ?CountSession},
     *     opening: ?float, opening_source: string, movements: Collection,
     *     net: float, expected: ?float, counted: ?float, unexplained: ?float,
     *     unknown_direction: Collection, session_variance: ?float
     * }
     */
    public function ladder(CountSession $session, object $item): array
    {
        $window = $this->windowForCountSession($session);
        $itemType = $item->item_type;
        $itemId = (int) ($itemType === 'product' ? $item->product_id : $item->ingredient_id);

        $movements = $this->movements(
            (int) $session->warehouse_id,
            $itemType,
            $itemId,
            $window['from'],
            $window['to'],
            (int) $session->id,
        );

        [$opening, $openingSource] = $this->openingAnchor($window['previous'], $itemType, $itemId);

        $counted = $item->counted_quantity === null ? null : (float) $item->counted_quantity;
        $expected = $opening === null ? null : round($opening + $movements['net'], 2);

        return [
            'window' => $window,
            'opening' => $opening,
            'opening_source' => $openingSource,
            'movements' => $movements['rows'],
            'net' => $movements['net'],
            'expected' => $expected,
            'counted' => $counted,
            'unexplained' => ($expected === null || $counted === null) ? null : round($counted - $expected, 2),
            'unknown_direction' => $movements['unknown'],
            'session_variance' => $item->variance === null ? null : (float) $item->variance,
        ];
    }

    /**
     * Opening balance comes from the previous count's counted figure, not
     * from a computed backwards walk — after review, trueUpStock() sets
     * live stock to exactly counted_quantity, so that number is a hard
     * anchor. Without a previous count there is nothing trustworthy to
     * anchor on and the ladder says so rather than inventing a zero.
     *
     * @return array{0: ?float, 1: string}
     */
    private function openingAnchor(?CountSession $previous, string $itemType, int $itemId): array
    {
        if (! $previous) {
            return [null, 'No earlier reviewed count at this warehouse — opening balance unknown.'];
        }

        $column = $itemType === 'product' ? 'product_id' : 'ingredient_id';

        $previousItem = $previous->items()
            ->where('item_type', $itemType)
            ->where($column, $itemId)
            ->first();

        if (! $previousItem || $previousItem->counted_quantity === null) {
            return [null, "Not counted in the previous session (#{$previous->id}) — opening balance unknown."];
        }

        return [
            (float) $previousItem->counted_quantity,
            "Counted in session #{$previous->id} on {$previous->reviewed_at?->format('M j, Y g:i A')}.",
        ];
    }

    /**
     * Signed quantity_change for every stock_adjustment referenced in this
     * batch, so adjustment rows can recover the direction their own
     * transaction threw away.
     *
     * @return array<int, float>
     */
    private function adjustmentChangeLookup(Collection $transactions): array
    {
        $ids = $transactions
            ->filter(fn ($t) => $t->type === 'adjustment' && str_starts_with((string) $t->reference, 'stock_adjustment:'))
            ->map(fn ($t) => (int) (explode(':', (string) $t->reference)[1] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return StockAdjustment::query()
            ->whereIn('id', $ids)
            ->pluck('quantity_change', 'id')
            ->map(fn ($change) => (float) $change)
            ->all();
    }

    /**
     * Batch lookup of what actually left the warehouse, keyed
     * "{product_id}:{order_id}" — one query rather than an N+1 per line.
     *
     * @return array<string, float>
     */
    private function deductedQuantityLookup(Collection $items): array
    {
        $references = $items
            ->filter(fn (OrderItem $i) => $i->item_type === 'product' && $i->product_id)
            ->map(fn (OrderItem $i) => "order:{$i->order_id}")
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return [];
        }

        return InventoryTransaction::query()
            ->where('type', 'sale')
            ->whereIn('reference', $references)
            ->get(['product_id', 'reference', 'quantity'])
            ->groupBy(fn (InventoryTransaction $t) => $t->product_id.':'.str_replace('order:', '', (string) $t->reference))
            ->map(fn (Collection $group) => (float) $group->sum('quantity'))
            ->all();
    }

    /**
     * Which ingredient ids actually moved for each order, so a menu item's
     * recipe can be checked against reality.
     *
     * @return array<int, array<int, int>> order_id => ingredient ids
     */
    private function orderIngredientLookup(Collection $items): array
    {
        $orderIds = $items
            ->filter(fn (OrderItem $i) => $i->item_type === 'menu_item' && $i->menu_item_id)
            ->pluck('order_id')
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        return IngredientTransaction::query()
            ->where('type', 'usage')
            ->whereIn('reference', $orderIds->map(fn ($id) => "order:{$id}"))
            ->get(['ingredient_id', 'reference'])
            ->groupBy(fn (IngredientTransaction $t) => (int) str_replace('order:', '', (string) $t->reference))
            ->map(fn (Collection $group) => $group->pluck('ingredient_id')->unique()->values()->all())
            ->all();
    }
}
