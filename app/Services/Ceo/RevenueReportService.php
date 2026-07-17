<?php

namespace App\Services\Ceo;

use App\Models\FolioLine;
use App\Models\InventoryTransaction;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use Illuminate\Support\Collection;

/**
 * Revenue/margin/mix reporting, backing both the Dashboard's Tier 1/3
 * cards and the Sales Report page. Everything is derived from OrderItem
 * (bar/restaurant, attributed by what was sold — a product/menu item's
 * category.type) plus OccupancyReportService's per-night room revenue
 * (rooms, attributed separately since a room-night charge is never an
 * OrderItem). "billed via folio" is the orthogonal secondary dimension:
 * an order with a non-null booking_id was posted to a room folio
 * (RoomOrderService is the only place that ever sets it), regardless of
 * whether the item itself is food or drink.
 *
 * Margin for product lines uses unit_cost_at_sale, snapshotted on the
 * matching sale InventoryTransaction (joined by product_id + the same
 * "order:{id}" reference InventoryService writes — unambiguous even
 * across repeat lines of the same product in one order, since they share
 * the same last_cost_price at deduction time). Falls back to the
 * product's current cost_price when null (pre-Prompt-1 history, or a
 * product that has never had last_cost_price set), incrementing the
 * caller-visible estimated-cost count.
 *
 * Menu items have no reliable per-order-item cost-at-sale: Prompt 1
 * snapshots unit_cost_at_sale per *ingredient* transaction, not per menu
 * item, and one order can plate two different dishes sharing an
 * ingredient in the same order — there is no unambiguous way to
 * reconstruct which ingredient transaction belongs to which dish's
 * recipe from the order-level reference alone. Rather than guess,
 * menu-item margin always uses current recipe cost and is always marked
 * estimated.
 */
class RevenueReportService
{
    public function __construct(private readonly OccupancyReportService $occupancy = new OccupancyReportService())
    {
    }

    /**
     * @param array{sold_by?: int, product_id?: int, category_id?: int, source?: string, billed_via_folio?: bool} $filters
     */
    public function lineItems(DateRange $range, array $filters = []): Collection
    {
        $query = OrderItem::query()
            ->with(['order.user', 'product.category', 'menuItem.category'])
            ->whereHas('order', function ($q) use ($range, $filters) {
                $q->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
                    ->where('is_return', false)
                    ->where('status', '!=', 'cancelled');

                if (! empty($filters['sold_by'])) {
                    $q->where('user_id', $filters['sold_by']);
                }

                if (array_key_exists('billed_via_folio', $filters)) {
                    $filters['billed_via_folio']
                        ? $q->whereNotNull('booking_id')
                        : $q->whereNull('booking_id');
                }
            });

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        $items = $query->get();
        $costAtSaleByProductOrder = $this->productUnitCostAtSaleLookup($items);

        $rows = $items->map(function (OrderItem $item) use ($costAtSaleByProductOrder) {
            $product = $item->product;
            $menuItem = $item->menuItem;
            $category = $product?->category ?? $menuItem?->category;
            // 'service'-type categories fold into Restaurant per the
            // confirmed Phase 2 answer — only 'drink' maps to Bar.
            $source = $category?->type === 'drink' ? 'bar' : 'restaurant';

            $quantity = (float) $item->quantity;
            $revenue = (float) $item->subtotal;

            if ($product) {
                $costAtSale = $costAtSaleByProductOrder["{$product->id}:{$item->order_id}"] ?? null;
                $costEstimated = is_null($costAtSale);
                $unitCost = $costEstimated ? (float) $product->cost_price : (float) $costAtSale;
            } else {
                // Menu items: see class docblock — always estimated.
                $costEstimated = true;
                $unitCost = $menuItem ? (float) $menuItem->total_recipe_cost : 0.0;
            }

            $cost = round($unitCost * $quantity, 2);

            return [
                'item_key' => $product ? "product_{$product->id}" : ($menuItem ? "menu_{$menuItem->id}" : 'unknown'),
                'item_name' => $item->product_name ?? $product?->name ?? $menuItem?->name ?? 'Unknown',
                'category_id' => $category?->id,
                'category_name' => $category?->name ?? 'Uncategorized',
                'source' => $source,
                'billed_via_folio' => (bool) $item->order?->booking_id,
                'sold_by_user_id' => $item->order?->user_id,
                'sold_by_name' => $item->order?->user?->name,
                'quantity' => $quantity,
                'revenue' => $revenue,
                'cost' => $cost,
                'cost_estimated' => $costEstimated,
                'margin' => round($revenue - $cost, 2),
                'date' => $item->order?->created_at,
            ];
        });

        if (! empty($filters['category_id'])) {
            $rows = $rows->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['source'])) {
            $rows = $rows->where('source', $filters['source']);
        }

        return $rows->values();
    }

    public function summary(Collection $lineItems): array
    {
        $revenue = (float) $lineItems->sum('revenue');
        $cost = (float) $lineItems->sum('cost');
        $margin = $revenue - $cost;

        return [
            'quantity' => (float) $lineItems->sum('quantity'),
            'revenue' => $revenue,
            'cost' => $cost,
            'margin' => $margin,
            'margin_pct' => $revenue > 0 ? round($margin / $revenue * 100, 2) : 0.0,
            'cost_estimated_count' => $lineItems->where('cost_estimated', true)->count(),
        ];
    }

    /**
     * Batch lookup of unit_cost_at_sale for every product line in one
     * query, keyed "{product_id}:{order_id}" — avoids an N+1 per item.
     * Menu items are excluded (see class docblock).
     */
    private function productUnitCostAtSaleLookup(Collection $items): array
    {
        $references = $items->filter(fn (OrderItem $i) => $i->item_type === 'product' && $i->product_id)
            ->map(fn (OrderItem $i) => "order:{$i->order_id}")
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return [];
        }

        return InventoryTransaction::query()
            ->where('type', 'sale')
            ->whereIn('reference', $references)
            ->get(['product_id', 'reference', 'unit_cost_at_sale'])
            ->filter(fn (InventoryTransaction $t) => ! is_null($t->unit_cost_at_sale))
            ->mapWithKeys(function (InventoryTransaction $t) {
                $orderId = str_replace('order:', '', $t->reference);

                return ["{$t->product_id}:{$orderId}" => (float) $t->unit_cost_at_sale];
            })
            ->all();
    }

    /**
     * Per-product/menu-item rollup with revenue contribution % against the
     * given line items' own total (i.e. against whatever filters already
     * narrowed them) — subtotal rows per category are the caller's to
     * build by grouping this collection's category_name.
     */
    public function productBreakdown(Collection $lineItems): Collection
    {
        $totalRevenue = (float) $lineItems->sum('revenue');

        return $lineItems->groupBy('item_key')->map(function (Collection $rows) use ($totalRevenue) {
            $first = $rows->first();
            $revenue = (float) $rows->sum('revenue');
            $cost = (float) $rows->sum('cost');
            $margin = $revenue - $cost;

            return [
                'item_name' => $first['item_name'],
                'category_name' => $first['category_name'],
                'source' => $first['source'],
                'quantity' => (float) $rows->sum('quantity'),
                'revenue' => $revenue,
                'cost' => $cost,
                'margin' => $margin,
                'margin_pct' => $revenue > 0 ? round($margin / $revenue * 100, 2) : 0.0,
                'revenue_contribution_pct' => $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 2) : 0.0,
            ];
        })->sortByDesc('revenue')->values();
    }

    /**
     * Bar / Restaurant / Rooms totals for the period — Rooms comes from
     * OccupancyReportService, never from OrderItem.
     */
    public function revenueMix(DateRange $range): array
    {
        $lineItems = $this->lineItems($range);
        $bar = (float) $lineItems->where('source', 'bar')->sum('revenue');
        $restaurant = (float) $lineItems->where('source', 'restaurant')->sum('revenue');
        $rooms = (float) $this->occupancy->summary($range)['total_room_revenue'];

        return [
            'bar' => $bar,
            'restaurant' => $restaurant,
            'rooms' => $rooms,
            'total' => $bar + $restaurant + $rooms,
        ];
    }

    public function totalRevenue(DateRange $range): float
    {
        $mix = $this->revenueMix($range);

        return $mix['total'];
    }

    /**
     * Per-day revenue across the range (order items + that night's room
     * revenue) — the daily revenue chart's primary series; call again with
     * the comparison DateRange for the ghost line.
     */
    public function dailyRevenueSeries(DateRange $range): Collection
    {
        $lineItems = $this->lineItems($range);
        $byDay = $lineItems->groupBy(fn ($row) => $row['date']?->toDateString());
        $roomsByDay = $this->occupancy->nightlyBreakdown($range)->keyBy(fn ($d) => $d['date']->toDateString());

        return collect($range->eachDate())->map(function ($date) use ($byDay, $roomsByDay) {
            $dateStr = $date->toDateString();
            $orderRevenue = (float) ($byDay->get($dateStr)?->sum('revenue') ?? 0);
            $roomRevenue = (float) ($roomsByDay->get($dateStr)['room_revenue'] ?? 0);

            return [
                'date' => $date,
                'revenue' => $orderRevenue + $roomRevenue,
            ];
        });
    }

    /**
     * Venue-wide Cash/POS/Transfer/Split mix across the period, per day —
     * merges POS-kiosk OrderPayments and hotel-folio payment FolioLines
     * (method labels normalized: FolioLine's "pos_terminal" -> "pos").
     */
    public function paymentMixSeries(DateRange $range): Collection
    {
        $orderPayments = OrderPayment::query()
            ->whereBetween('paid_at', [$range->startBoundary(), $range->endBoundary()])
            ->get(['amount', 'method', 'paid_at']);

        $folioPayments = FolioLine::query()
            ->where('type', 'payment')
            ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->get(['amount', 'payment_method', 'created_at']);

        $normalized = $orderPayments->map(fn (OrderPayment $p) => [
            'date' => $p->paid_at->toDateString(),
            'method' => $this->normalizeMethod($p->method),
            'amount' => (float) $p->amount,
        ])->concat($folioPayments->map(fn (FolioLine $l) => [
            'date' => $l->created_at->toDateString(),
            'method' => $this->normalizeMethod($l->payment_method),
            // FolioLine payment amounts are stored negative (credits) —
            // the mix chart wants the magnitude actually collected.
            'amount' => abs((float) $l->amount),
        ]));

        return collect($range->eachDate())->map(function ($date) use ($normalized) {
            $dateStr = $date->toDateString();
            $dayRows = $normalized->where('date', $dateStr);

            return [
                'date' => $date,
                'by_method' => $dayRows->groupBy('method')->map(fn ($rows) => (float) $rows->sum('amount'))->all(),
            ];
        });
    }

    private function normalizeMethod(?string $method): string
    {
        return match ($method) {
            'pos_terminal' => 'pos',
            null => 'unknown',
            default => $method,
        };
    }
}
