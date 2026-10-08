<?php

namespace App\Services;

use App\Models\CountBreakdown;
use App\Models\CountBreakdownLine;
use App\Models\CountBreakdownMovement;
use App\Models\CountOpenOrder;
use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\DamageReport;
use App\Models\IngredientTransaction;
use App\Models\IngredientTransferItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\UnreturnableVoid;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Freezes a count's per-item movement breakdown at the moment it locks.
 *
 * Called from inside CountSessionService's own lock transactions (the
 * dual-PIN seal, the solo store-count submit, and submitForReview for
 * closing/solo-opening handovers), so a lock that fails writes none of
 * this, and a lock that succeeds always has it.
 *
 * Read-only toward everything except its own tables: it never writes a
 * stock transaction, a debt, a discrepancy, or touches seal/resolution
 * state (tests/Feature/Architecture/CountBreakdownBoundaryTest.php).
 *
 * The sealed figures stay the source of truth. expected_remaining,
 * counted and variance_qty are copied from the CountSessionItem the seal
 * just wrote; the ledger only explains them. Whatever part of the expected
 * figure the ledger cannot explain (a stock change written without a
 * direction, see StockTraceService) is shown as its own
 * "unrecorded_change" figure rather than guessed, so every pop-up still
 * adds up to the number it was opened from.
 */
class CountBreakdownSnapshotService
{
    /** Order destination whose open tickets explain a variance at this count type. */
    private const DESTINATION_FOR_TYPE = [
        'bar_handover' => 'bar',
        'kitchen_handover' => 'kitchen',
    ];

    /** Negative stock adjustments with these reasons count as damage/waste. */
    private const DAMAGE_REASONS = ['damage', 'spillage_wastage', 'expiry'];

    public function __construct(private StockTraceService $trace = new StockTraceService) {}

    /**
     * Set only while rebuilding an old count: the moment it was sealed,
     * so cost prices are read as they stood then rather than today.
     */
    private ?CarbonInterface $asOf = null;

    public function capture(CountSession $session): CountBreakdown
    {
        return $this->build($session, reconstruct: false);
    }

    /**
     * Rebuild the breakdown of a count sealed before breakdowns were
     * captured, from the records still in the database. The window,
     * movements and sealed figures come out the same as a live capture
     * would have given; the differences are spelled out on the page by
     * reconstructed_at:
     *  - open orders at handover are not rebuilt (order statuses have
     *    moved on since, so "was it still open then?" can't be answered);
     *  - cost price is the cost recorded on sales up to the seal, not the
     *    product's cost today;
     *  - anything changed after the seal (an order line removed by a later
     *    return) is flagged the same way the live capture flags it.
     */
    public function reconstruct(CountSession $session): CountBreakdown
    {
        if (! $session->isReviewed()) {
            throw new \Exception("Count #{$session->id} is not sealed, so there is nothing to rebuild.");
        }

        if (CountBreakdown::where('count_session_id', $session->id)->exists()) {
            throw new \Exception("Count #{$session->id} already has a breakdown.");
        }

        return $this->build($session, reconstruct: true);
    }

    private function build(CountSession $session, bool $reconstruct): CountBreakdown
    {
        $session->loadMissing(['items.product', 'items.ingredient', 'items.review', 'outgoingUser', 'incomingUser', 'witnessUser', 'openedBy']);

        $window = $this->trace->windowForCountSession($session);
        $previous = $window['previous'];
        $previous?->loadMissing(['items', 'outgoingUser', 'incomingUser', 'witnessUser', 'openedBy']);
        $this->asOf = $reconstruct ? $window['to'] : null;

        $breakdown = CountBreakdown::create([
            'count_session_id' => $session->id,
            'previous_count_session_id' => $previous?->id,
            'window_from' => $window['from'],
            'window_to' => $window['to'],
            'reconstructed_at' => $reconstruct ? now() : null,
            ...$this->people($session),
        ]);

        $rawByItem = $session->items->mapWithKeys(fn (CountSessionItem $item) => [
            $item->id => $this->ledgerRows($session, $item, $window, $previous),
        ]);

        $context = $this->loadContext($rawByItem->flatten(1));

        foreach ($session->items as $item) {
            $this->writeLine($breakdown, $session, $item, $previous, $rawByItem[$item->id], $context);
        }

        if (! $reconstruct) {
            $this->captureOpenOrders($session, $window['to']);
        }

        $this->asOf = null;

        return $breakdown;
    }

    /**
     * Who counted and who signed, by name, as the counter-signature pop-up
     * shows it. Mirrors how each lock path identifies its people.
     *
     * @return array<string, mixed>
     */
    private function people(CountSession $session): array
    {
        if ($session->isHandoverWithSuccessor()) {
            $unwitnessed = $session->isUnwitnessed();

            return [
                'counted_by_name' => ($unwitnessed ? $session->incomingUser : $session->outgoingUser)?->name,
                'counted_at' => $unwitnessed ? ($session->reviewed_at ?? now()) : $session->confirmed_by_outgoing_at,
                'first_signer_label' => $unwitnessed ? 'Witness' : 'Outgoing',
                'first_signer_name' => ($unwitnessed ? $session->witnessUser : $session->outgoingUser)?->name,
                'second_signer_label' => 'Incoming',
                'second_signer_name' => $session->incomingUser?->name,
            ];
        }

        if ($session->isHandover()) {
            return [
                'counted_by_name' => ($session->outgoingUser ?? $session->openedBy)?->name,
                'counted_at' => $session->submitted_for_review_at ?? now(),
                'first_signer_label' => $session->outgoing_user_id ? 'Outgoing' : null,
                'first_signer_name' => $session->outgoingUser?->name,
                'second_signer_label' => $session->isClosing() ? 'Closing witness' : 'Incoming',
                'second_signer_name' => $session->incomingUser?->name,
            ];
        }

        return [
            'counted_by_name' => $session->openedBy?->name,
            'counted_at' => $session->reviewed_at ?? $session->submitted_for_review_at ?? now(),
            'first_signer_label' => 'Counter',
            'first_signer_name' => $session->openedBy?->name,
            'second_signer_label' => null,
            'second_signer_name' => null,
        ];
    }

    /**
     * Direction-resolved ledger rows for one item in the window, minus
     * every count true-up (this session's closes the variance being
     * explained; the previous one's is already inside brought forward).
     */
    private function ledgerRows(CountSession $session, CountSessionItem $item, array $window, ?CountSession $previous): Collection
    {
        $itemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);

        $rows = $this->trace->movements(
            (int) $session->warehouse_id,
            $item->item_type,
            $itemId,
            $window['from'],
            $window['to'],
            (int) $session->id,
        )['rows'];

        return $rows
            ->reject(fn (array $row) => $row['kind'] === 'count_true_up')
            ->map(fn (array $row) => $row + ['item_type' => $item->item_type, 'item_id' => $itemId])
            ->values();
    }

    /**
     * Everything the rows point at, loaded once for the whole count rather
     * than per row.
     */
    private function loadContext(Collection $rows): array
    {
        $refId = fn (string $prefix) => $rows
            ->filter(fn ($r) => str_starts_with((string) $r['reference'], $prefix))
            ->map(fn ($r) => (int) (explode(':', (string) $r['reference'])[1] ?? 0))
            ->filter()->unique()->values();

        $orderIds = $refId('order:');
        $transferIds = $refId('transfer:');

        $orders = Order::query()->with(['user', 'processedByUser', 'items.menuItem.recipes'])->whereIn('id', $orderIds)->get()->keyBy('id');

        return [
            'orders' => $orders,
            'order_activity' => $this->orderActivity($orderIds),
            'comps' => UnreturnableVoid::query()->with(['orderItem', 'manager'])->whereIn('order_id', $orderIds)->get()->groupBy('order_id'),
            'transfers' => StockTransfer::query()->with('user')->whereIn('id', $transferIds)->get()->keyBy('id'),
            'transfer_lines' => StockTransferItem::query()->with('receivedBy')->whereIn('stock_transfer_id', $transferIds)->get()->groupBy('stock_transfer_id'),
            'ingredient_transfer_lines' => IngredientTransferItem::query()->with('receivedBy')->whereIn('stock_transfer_id', $transferIds)->get()->groupBy('stock_transfer_id'),
            'damage_reports' => DamageReport::query()->with(['reportedBy', 'resolvedBy'])->whereIn('id', $refId('damage_report:'))->get()->keyBy('id'),
            'adjustments' => StockAdjustment::query()->with(['requestedBy', 'reviewedBy'])->whereIn('id', $refId('stock_adjustment:'))->get()->keyBy('id'),
        ];
    }

    /**
     * When each order was marked ready and when/by whom it was cancelled,
     * read from the order activity log (orders keep no such timestamps of
     * their own).
     *
     * @return array<int, array{ready_at: mixed, cancelled_at: mixed, cancelled_by: ?string}>
     */
    private function orderActivity(Collection $orderIds): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }

        $activities = Activity::query()
            ->with('causer')
            ->where('log_name', 'order')
            ->where('subject_type', (new Order)->getMorphClass())
            ->whereIn('subject_id', $orderIds)
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($activities as $activity) {
            $status = $activity->attribute_changes?->get('attributes')['status'] ?? null;
            $orderId = (int) $activity->subject_id;
            $result[$orderId] ??= ['ready_at' => null, 'cancelled_at' => null, 'cancelled_by' => null];

            if ($status === 'ready' && $result[$orderId]['ready_at'] === null) {
                $result[$orderId]['ready_at'] = $activity->created_at;
            }

            if ($status === 'cancelled' && $result[$orderId]['cancelled_at'] === null) {
                $result[$orderId]['cancelled_at'] = $activity->created_at;
                $result[$orderId]['cancelled_by'] = $activity->causer?->name;
            }
        }

        return $result;
    }

    private function writeLine(CountBreakdown $breakdown, CountSession $session, CountSessionItem $item, ?CountSession $previous, Collection $rows, array $context): void
    {
        $isProduct = $item->item_type === 'product';
        $movements = $this->classify($rows, $context, $isProduct);

        [$broughtForward, $bfMovement, $bfNote] = $this->broughtForward($item, $previous);

        $sum = fn (string $figure) => round((float) $movements->where('figure', $figure)->where('status', 'active')->sum('quantity'), 2);

        $transferred = $sum('transferred');
        $returns = $sum('returns');
        $otherIn = $sum('other_in');
        $sold = $sum('sold');
        $damages = $sum('damages');
        $otherOut = $sum('other_out');

        $available = round($broughtForward + $transferred + $returns + $otherIn, 2);
        $ledgerExpected = round($available - $sold - $damages - $otherOut, 2);

        $expected = round((float) $item->adjusted_expected_quantity, 2);
        $counted = round((float) $item->counted_quantity, 2);
        $variance = round((float) ($item->variance ?? ($counted - $expected)), 2);
        $unrecorded = round($expected - $ledgerExpected, 2);

        if (abs($unrecorded) > 0.0001) {
            $movements->push([
                'figure' => 'unrecorded',
                'source_type' => 'reconciliation',
                'quantity' => $unrecorded,
                'status' => 'active',
                'label' => 'Stock changed with no movement recorded to explain it',
            ]);
        }

        $sellPrice = $item->unit_selling_price !== null ? (float) $item->unit_selling_price : $this->sellingPrice($item);
        $costPrice = $this->costPrice($item);

        $line = CountBreakdownLine::create([
            'count_breakdown_id' => $breakdown->id,
            'count_session_id' => $session->id,
            'count_session_item_id' => $item->id,
            'warehouse_id' => $session->warehouse_id,
            'section' => $item->item_type,
            'item_id' => $isProduct ? $item->product_id : $item->ingredient_id,
            'item_name' => $item->itemName(),
            'unit' => $isProduct ? ($item->product?->base_unit ?? null) : ($item->ingredient?->unit_name ?? null),
            'pack_unit_name' => $this->packName($item),
            'units_per_pack' => $this->packSize($item),
            'brought_forward' => $broughtForward,
            'transferred_in' => $transferred,
            'returns_in' => $returns,
            'other_in' => $otherIn,
            'available' => $available,
            'sold_qty' => $sold,
            'sales_amount' => $isProduct ? round((float) $movements->where('figure', 'sold')->where('status', 'active')->sum('amount'), 2) : null,
            'damages_writeoffs' => $damages,
            'other_out' => $otherOut,
            'unrecorded_change' => $unrecorded,
            'expected_remaining' => $expected,
            'counted' => $counted,
            'variance_qty' => $variance,
            'unit_selling_price' => $sellPrice,
            'unit_cost_price' => $costPrice,
            'variance_value_selling' => $item->variance_value !== null ? (float) $item->variance_value : round($variance * $sellPrice, 2),
            'variance_value_cost' => $costPrice === null ? null : round($variance * $costPrice, 2),
            'has_movement' => $rows->isNotEmpty(),
            'brought_forward_note' => $bfNote,
        ]);

        if ($bfMovement) {
            $movements->prepend($bfMovement);
        }

        foreach ($movements as $movement) {
            CountBreakdownMovement::create(['count_breakdown_line_id' => $line->id] + $movement);
        }
    }

    /**
     * Brought forward is what the previous sealed count left: its counted
     * figure, which that seal trued live stock up to.
     *
     * @return array{0: float, 1: ?array, 2: ?string}
     */
    private function broughtForward(CountSessionItem $item, ?CountSession $previous): array
    {
        if (! $previous) {
            return [0.0, null, 'No earlier sealed count at this location, so nothing was brought forward.'];
        }

        $column = $item->item_type === 'product' ? 'product_id' : 'ingredient_id';
        $previousItem = $previous->items
            ->where('item_type', $item->item_type)
            ->firstWhere($column, $item->{$column});

        if (! $previousItem || $previousItem->counted_quantity === null) {
            return [0.0, null, 'Not on the previous count, so nothing was brought forward.'];
        }

        $people = $this->people($previous);
        $quantity = round((float) $previousItem->counted_quantity, 2);

        return [$quantity, [
            'figure' => 'brought_forward',
            'source_type' => 'count_session',
            'source_id' => $previous->id,
            'quantity' => $quantity,
            'status' => 'active',
            'label' => 'Previous sealed count',
            'recorder_name' => $people['counted_by_name'],
            'approver_name' => collect([$people['first_signer_name'], $people['second_signer_name']])->filter()->unique()->implode(' & ') ?: null,
            'recorded_at' => $previous->reviewed_at,
        ], null];
    }

    /**
     * Turn ledger rows into pop-up movements, each under the figure it
     * explains. A cancelled sale and the restock it caused are folded into
     * one crossed-out sale rather than appearing as a sale plus a return.
     */
    private function classify(Collection $rows, array $context, bool $isProduct): Collection
    {
        $orderRef = fn (array $row) => str_starts_with((string) $row['reference'], 'order:')
            ? (int) (explode(':', (string) $row['reference'])[1] ?? 0)
            : null;

        $voidedOrders = $this->voidedOrderIds($rows, $context, $orderRef);
        $movements = collect();

        foreach ($rows as $row) {
            $signed = $row['signed_quantity'];
            $quantity = abs((float) $row['quantity']);
            $orderId = $orderRef($row);
            $order = $orderId ? ($context['orders'][$orderId] ?? null) : null;

            if ($signed === null) {
                $movements->push(array_merge($this->base($row, 'unrecorded', $quantity), [
                    'status' => 'info',
                    'label' => $row['label'].' (direction not recorded)',
                ]));

                continue;
            }

            if ($orderId && $signed < 0) {
                $movements->push($this->saleMovement($row, $quantity, $order, $context, $isProduct, in_array($orderId, $voidedOrders, true)));

                continue;
            }

            if ($orderId && $signed > 0) {
                if (in_array($orderId, $voidedOrders, true)) {
                    continue; // already shown as the crossed-out sale it reversed
                }

                $movements->push($this->returnMovement($row, $quantity, $order));

                continue;
            }

            $movements->push(match ($row['kind']) {
                'room_charge' => array_merge($this->base($row, $signed < 0 ? 'sold' : 'returns', $quantity), ['label' => 'Room charge']),
                'transfer' => $this->transferMovement($row, $quantity, $signed, $context),
                'damage' => $this->damageMovement($row, $quantity, $signed, $context),
                'stock_adjustment' => $this->adjustmentMovement($row, $quantity, $signed, $context),
                default => $this->base($row, $signed < 0 ? 'other_out' : 'other_in', $quantity),
            });
        }

        return $movements;
    }

    /**
     * Orders (not return tickets) whose sale in this window was fully
     * given back by a cancellation restock also in this window.
     *
     * @return array<int, int>
     */
    private function voidedOrderIds(Collection $rows, array $context, \Closure $orderRef): array
    {
        $byOrder = $rows->filter(fn ($r) => $orderRef($r) && $r['signed_quantity'] !== null)->groupBy($orderRef);
        $voided = [];

        foreach ($byOrder as $orderId => $group) {
            $order = $context['orders'][$orderId] ?? null;

            if (! $order || $order->is_return) {
                continue;
            }

            $sold = (float) $group->where('signed_quantity', '<', 0)->sum('quantity');
            $restocked = (float) $group->where('signed_quantity', '>', 0)->sum('quantity');

            if ($sold > 0 && abs($sold - $restocked) < 0.0001) {
                $voided[] = (int) $orderId;
            }
        }

        return $voided;
    }

    private function base(array $row, string $figure, float $quantity): array
    {
        return [
            'figure' => $figure,
            'source_type' => $row['item_type'] === 'product' ? 'inventory_transaction' : 'ingredient_transaction',
            'source_id' => $row['id'],
            'quantity' => $quantity,
            'status' => 'active',
            'label' => $row['label'],
            'recorder_name' => $row['user_name'],
            'recorded_at' => $row['at'],
        ];
    }

    private function saleMovement(array $row, float $quantity, ?Order $order, array $context, bool $isProduct, bool $voided): array
    {
        $activity = $order ? ($context['order_activity'][$order->id] ?? []) : [];
        $meta = ['order_number' => $order?->order_number];
        $amount = null;

        if ($isProduct) {
            $orderItem = $order?->items->firstWhere('product_id', $row['item_id']);
            $unitPrice = $orderItem ? (float) $orderItem->unit_price : null;

            if ($unitPrice === null) {
                // The order line was removed (fully returned). Its price
                // was never stored anywhere else, so fall back to the
                // product's price at snapshot time and say so.
                $unitPrice = (float) (\App\Models\Product::withTrashed()->find($row['item_id'])?->price ?? 0);
                $meta['price_estimated'] = true;
            }

            $comps = collect($context['comps'][$order?->id] ?? [])
                ->filter(fn (UnreturnableVoid $v) => (int) $v->orderItem?->product_id === (int) $row['item_id']);

            $amount = round($quantity * $unitPrice, 2);

            if ($comps->isNotEmpty()) {
                $amount = max(0, round($amount - (float) $comps->sum('amount'), 2));
                $meta['comp'] = [
                    'quantity' => (float) $comps->sum('quantity'),
                    'reason' => $comps->pluck('reason_code')->unique()->implode(', '),
                    'by' => $comps->map(fn ($v) => $v->manager?->name)->filter()->unique()->implode(', '),
                ];
            }

            $meta['unit_price'] = $unitPrice;
        } else {
            $meta['dishes'] = $order?->items
                ->filter(fn (OrderItem $i) => $i->menuItem && $i->menuItem->recipes->contains('ingredient_id', $row['item_id']))
                ->map(fn (OrderItem $i) => $i->menuItem->name.' ×'.(float) $i->quantity)
                ->values()->all() ?? [];
        }

        return array_merge($this->base($row, 'sold', $quantity), [
            'amount' => $amount,
            'status' => $voided ? 'voided' : 'active',
            'label' => $voided ? 'Cancelled sale' : ($order?->booking_id ? 'Room order' : 'Sale'),
            'order_id' => $order?->id,
            'waiter_name' => $order?->user?->name ?? ($order?->guest_id ? 'Guest order' : null),
            'placed_at' => $order?->created_at,
            'ready_at' => $activity['ready_at'] ?? null,
            'voided_by_name' => $voided ? ($activity['cancelled_by'] ?? null) : null,
            'voided_at' => $voided ? ($activity['cancelled_at'] ?? null) : null,
            'meta' => $meta,
        ]);
    }

    private function returnMovement(array $row, float $quantity, ?Order $order): array
    {
        $isTicket = (bool) $order?->is_return;

        return array_merge($this->base($row, 'returns', $quantity), [
            'label' => $isTicket ? 'Return confirmed' : 'Restocked after cancel (sold before this count window)',
            'order_id' => $order?->id,
            'waiter_name' => $order?->user?->name,
            'recorder_name' => $isTicket ? ($order?->processedByUser?->name ?? $row['user_name']) : $row['user_name'],
            'reason' => $isTicket ? $order?->items->first()?->return_reason : null,
            'meta' => ['order_number' => $order?->order_number],
        ]);
    }

    private function transferMovement(array $row, float $quantity, float $signed, array $context): array
    {
        $transferId = (int) (explode(':', (string) $row['reference'])[1] ?? 0);
        $transfer = $context['transfers'][$transferId] ?? null;
        $lines = $row['item_type'] === 'product'
            ? collect($context['transfer_lines'][$transferId] ?? [])->where('product_id', $row['item_id'])
            : collect($context['ingredient_transfer_lines'][$transferId] ?? [])->where('ingredient_id', $row['item_id']);
        $line = $lines->first();

        return array_merge($this->base($row, $signed > 0 ? 'transferred' : 'other_out', $quantity), [
            'source_type' => 'stock_transfer',
            'source_id' => $transferId,
            'label' => $signed > 0 ? 'Transfer in' : 'Transfer out',
            'sender_name' => $transfer?->user?->name,
            'sent_at' => $transfer?->created_at,
            'receiver_name' => $line?->receivedBy?->name ?? $row['user_name'],
            'received_at' => $line?->received_at ?? $row['at'],
            'meta' => ['transfer_number' => $transfer?->transfer_number],
        ]);
    }

    private function damageMovement(array $row, float $quantity, float $signed, array $context): array
    {
        $reportId = (int) (explode(':', (string) $row['reference'])[1] ?? 0);
        $report = $context['damage_reports'][$reportId] ?? null;

        return array_merge($this->base($row, $signed < 0 ? 'damages' : 'other_in', $quantity), [
            'source_type' => 'damage_report',
            'source_id' => $reportId ?: null,
            'label' => 'Damage report',
            'reason' => $report?->note,
            'recorder_name' => $report?->reportedBy?->name ?? $row['user_name'],
            'approver_name' => $report?->resolvedBy?->name,
            'recorded_at' => $report?->created_at ?? $row['at'],
            'received_at' => $report?->resolved_at,
        ]);
    }

    private function adjustmentMovement(array $row, float $quantity, float $signed, array $context): array
    {
        $adjustmentId = (int) (explode(':', (string) $row['reference'])[1] ?? 0);
        $adjustment = $context['adjustments'][$adjustmentId] ?? null;
        $isDamage = $signed < 0 && in_array($adjustment?->reason, self::DAMAGE_REASONS, true);

        return array_merge($this->base($row, $isDamage ? 'damages' : ($signed < 0 ? 'other_out' : 'other_in'), $quantity), [
            'source_type' => 'stock_adjustment',
            'source_id' => $adjustmentId ?: null,
            'label' => 'Stock adjustment'.($adjustment ? ' ('.str_replace('_', ' ', $adjustment->reason).')' : ''),
            'reason' => $adjustment?->notes,
            'recorder_name' => $adjustment?->requestedBy?->name ?? $row['user_name'],
            'approver_name' => $adjustment?->reviewedBy?->name,
            'recorded_at' => $adjustment?->created_at ?? $row['at'],
            'received_at' => $adjustment?->reviewed_at,
        ]);
    }

    /**
     * Every line of every ticket for this station still placed-but-not-
     * ready when the count locked.
     */
    private function captureOpenOrders(CountSession $session, $lockedAt): void
    {
        $destination = self::DESTINATION_FOR_TYPE[$session->type] ?? null;

        if (! $destination) {
            return;
        }

        $orders = Order::query()
            ->with(['user', 'items.product', 'items.menuItem'])
            ->where('destination', $destination)
            ->whereIn('status', ['pending', 'preparing'])
            ->where('is_return', false)
            ->where('created_at', '<=', $lockedAt)
            ->orderBy('created_at')
            ->get();

        foreach ($orders as $order) {
            foreach ($order->items as $orderItem) {
                CountOpenOrder::create([
                    'count_session_id' => $session->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'item_name' => $orderItem->product_name ?? $orderItem->product?->name ?? $orderItem->menuItem?->name ?? 'Item',
                    'quantity' => (float) $orderItem->quantity,
                    'waiter_name' => $order->user?->name ?? ($order->guest_id ? 'Guest order' : null),
                    'order_status' => $order->status,
                    'placed_at' => $order->created_at,
                ]);
            }
        }
    }

    /**
     * Same price source the seal uses (CountSessionService::unitSellingPrice):
     * product selling price, or an ingredient's last purchase cost.
     */
    private function sellingPrice(CountSessionItem $item): float
    {
        return $item->item_type === 'product'
            ? (float) ($item->product?->price ?? 0)
            : $this->lastPurchasePrice((int) $item->ingredient_id);
    }

    /**
     * Cost per unit at lock time: a product's last recorded purchase cost
     * (null when none was ever recorded — never a faked zero), or an
     * ingredient's last purchase cost.
     */
    private function costPrice(CountSessionItem $item): ?float
    {
        if ($item->item_type === 'product') {
            $cost = $this->asOf
                ? InventoryTransaction::where('product_id', $item->product_id)
                    ->where('type', 'sale')
                    ->whereNotNull('unit_cost_at_sale')
                    ->where('created_at', '<=', $this->asOf)
                    ->latest('id')
                    ->value('unit_cost_at_sale')
                : $item->product?->last_cost_price;

            return $cost === null ? null : (float) $cost;
        }

        $cost = $this->lastPurchasePrice((int) $item->ingredient_id);

        return $cost > 0 ? $cost : null;
    }

    private function lastPurchasePrice(int $ingredientId): float
    {
        return (float) (IngredientTransaction::where('ingredient_id', $ingredientId)
            ->where('type', 'purchase')
            ->whereNotNull('cost_per_unit')
            ->when($this->asOf, fn ($q) => $q->where('created_at', '<=', $this->asOf))
            ->latest('id')
            ->value('cost_per_unit') ?? 0);
    }

    private function packName(CountSessionItem $item): ?string
    {
        $owner = $item->item_type === 'product' ? $item->product : $item->ingredient;

        return $this->packSize($item) ? $owner?->purchase_unit_name : null;
    }

    private function packSize(CountSessionItem $item): ?int
    {
        $owner = $item->item_type === 'product' ? $item->product : $item->ingredient;
        $size = (int) ($owner?->units_per_purchase_unit ?? 0);

        return $size >= 2 ? $size : null;
    }
}
