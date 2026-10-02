<?php

namespace App\Services\Guest;

use App\Models\Booking;
use App\Models\FolioLine;
use App\Models\GuestDeliveryRefusal;
use App\Models\GuestPaymentClaim;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTableSession;
use App\Models\GuestWaiterCall;
use App\Models\Order;
use App\Models\TableMove;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The guest's live bill (Phase 4, D19). Read-only.
 *
 * The bill is the orders on the sitting's table from bill_from_at on —
 * the table identity the POS uses (orders.table_id) — while the sitting is
 * open, plus the guest request lines not yet ordered (greyed). A sitting
 * that moved (D4) takes its time on each table in turn plus the orders it
 * carried along, so a destination table's earlier, already-paid orders
 * from other guests never appear on it.
 *
 * What goes to a phone carries no internal ids, no staff surnames and
 * nothing from another table.
 *
 * A ROOM's bill (Phase 5, D25) is its stay's folio — shown only to trusted
 * phones; the caller checks that (GuestTrustedDevice).
 */
class GuestBillService
{
    /** Still owed: the statuses fast Mark Paid works through. */
    public const UNPAID = ['pending', 'preparing', 'ready', 'served'];

    public const PAID = ['paid', 'partial'];

    public const TRACKER_STEPS = ['Waiting', 'Confirmed', 'Preparing / At the bar', 'On the way', 'Ready'];

    /**
     * Every order on this sitting's bill, in any status. Return tickets are
     * left out unless asked for (they carry no money; a move takes them
     * along so the old table doesn't look occupied).
     */
    public static function orders(GuestTableSession $session, bool $withReturnTickets = false): Builder
    {
        $moves = TableMove::where('guest_table_session_id', $session->id)->orderBy('id')->get();
        $from = $session->billFrom();
        $carried = $moves->flatMap(fn (TableMove $move) => $move->order_ids)->unique()->values()->all();

        return Order::query()
            ->when(! $withReturnTickets, fn ($q) => $q->where(fn ($q) => $q->where('is_return', false)->orWhereNull('is_return')))
            ->where(function ($q) use ($moves, $from, $session, $carried) {
                // One time window per table the sitting has used.
                foreach ($moves as $move) {
                    $q->orWhere(fn ($w) => $w->where('table_id', $move->from_table_id)
                        ->where('created_at', '>=', $from)
                        ->where('created_at', '<', $move->created_at));
                    $from = $move->created_at->greaterThan($from) ? $move->created_at : $from;
                }

                $q->orWhere(fn ($w) => $w->where('table_id', $session->table_id)->where('created_at', '>=', $from));

                if ($carried) {
                    $q->orWhereIn('id', $carried);
                }
            });
    }

    /** @return Collection<int, Order> */
    public static function unpaidOrders(GuestTableSession $session): Collection
    {
        return self::orders($session)->whereIn('status', self::UNPAID)->orderBy('created_at')->orderBy('id')->get();
    }

    /** What is still owed, in naira (2 dp). */
    public static function unpaidTotal(GuestTableSession $session): float
    {
        return round(self::unpaidOrders($session)->sum(fn (Order $o) => max(0, (float) $o->total_amount - (float) $o->amount_paid)), 2);
    }

    /** Guest lines still waiting to become an order (pending / at the bar / needing a waiter). */
    public static function waitingLines(GuestTableSession $session): Collection
    {
        return GuestRequestItem::query()
            ->whereIn('status', ['pending', ...GuestRequestItem::WAITING])
            ->whereHas('request', fn ($q) => $q->where('guest_table_session_id', $session->id)
                ->whereIn('status', [GuestRequest::STATUS_PENDING, GuestRequest::STATUS_CONFIRMED]))
            ->with('request')
            ->get();
    }

    /** @return array<string, mixed> the payload for a guest's phone */
    public function forSession(GuestTableSession $session, ?string $deviceId = null): array
    {
        $orders = self::orders($session)->with('items')->orderBy('created_at')->orderBy('id')->get();
        $unpaid = $orders->whereIn('status', self::UNPAID);
        $requestLines = GuestRequestItem::with(['request', 'order'])
            ->whereHas('request', fn ($q) => $q->where('guest_table_session_id', $session->id))
            ->get();
        $byOrderItem = $requestLines->whereNotNull('order_item_id')->keyBy('order_item_id');

        $onBill = [];
        $paid = [];
        $unavailable = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $line = $this->line($item->product_name, (int) $item->quantity, (float) $item->unit_price, $item->chips, $item->note);

                if ($order->status === 'cancelled' || (int) $item->quantity === 0) {
                    $unavailable[] = $line + ['status_label' => 'Removed by staff', 'reason' => 'Removed by staff'];
                } elseif (in_array($order->status, self::PAID, true)) {
                    $paid[] = $line + ['status_label' => 'Paid'];
                } elseif (in_array($order->status, self::UNPAID, true)) {
                    $guestLine = $byOrderItem->get($item->id);
                    $onBill[] = $line + ['status_label' => $guestLine ? self::lineStatus($guestLine)[0] : self::orderStatusLabel($order)];
                }
            }
        }

        $waiting = $requestLines
            ->filter(fn ($l) => in_array($l->status, ['pending', ...GuestRequestItem::WAITING], true)
                && in_array($l->request->status, [GuestRequest::STATUS_PENDING, GuestRequest::STATUS_CONFIRMED], true))
            ->map(fn ($l) => $this->line($l->name_snapshot, $l->finalQuantity(), (float) $l->unit_price_snapshot, $l->chips, $l->note)
                + ['status_label' => self::lineStatus($l)[0]])
            ->values()->all();

        foreach ($requestLines as $l) {
            if ($l->status === 'removed' || ($l->status === 'cancelled' && $l->removed_reason)) {
                $reason = self::reason($l);
                $unavailable[] = $this->line($l->name_snapshot, $l->quantity_requested, (float) $l->unit_price_snapshot, $l->chips, $l->note)
                    + ['status_label' => 'Unavailable', 'reason' => $reason];
            }
        }

        $bill = round($unpaid->sum(fn (Order $o) => max(0, (float) $o->total_amount - (float) $o->amount_paid)), 2);
        $paidTotal = round($orders->whereNotIn('status', ['cancelled'])->sum(fn (Order $o) => (float) $o->amount_paid), 2);
        $openClaims = GuestPaymentClaim::where('guest_table_session_id', $session->id)->open()->get();
        $claimed = round((float) $openClaims->sum('amount'), 2);

        $myClaims = $deviceId
            ? GuestPaymentClaim::with('transferAccount')->where('guest_table_session_id', $session->id)->where('device_id', $deviceId)->latest('id')->get()
            : collect();
        $myCall = $deviceId
            ? GuestWaiterCall::where('table_id', $session->table_id)->where('device_id', $deviceId)->latest('id')->first()
            : null;
        $callLive = $myCall && $myCall->status === GuestWaiterCall::STATUS_OPEN && $myCall->created_at->gt(now()->subMinutes(GuestWaiterCall::EXPIRES_AFTER_MINUTES));

        return [
            'totals' => [
                'bill' => $this->money($bill),
                'paid' => $this->money($paidTotal),
                'claimed' => $this->money($claimed),
                'remaining' => $this->money(max(0, $bill - $claimed)),
            ],
            'tracker' => $this->tracker(GuestRequest::where('guest_table_session_id', $session->id), $deviceId),
            'sections' => [
                'on_bill' => $onBill,
                'waiting' => $waiting,
                'unavailable' => $unavailable,
                'paid' => $paid,
            ],
            'claims' => $myClaims->map(fn (GuestPaymentClaim $c) => [
                'id' => $c->id,
                'payer_name' => $c->payer_name,
                'amount' => $this->money((float) $c->amount),
                'account' => $c->transferAccount?->bank_name,
                'status' => $c->status,
                'status_label' => match ($c->status) {
                    GuestPaymentClaim::STATUS_OPEN => 'Claim sent — your waiter will confirm.',
                    GuestPaymentClaim::STATUS_WITHDRAWN => 'Withdrawn',
                    default => 'Settled by your waiter',
                },
            ])->values()->all(),
            'call' => $myCall ? [
                'reason' => $myCall->label(),
                'status' => $callLive ? 'open' : ($myCall->status === GuestWaiterCall::STATUS_ACKNOWLEDGED ? 'acknowledged' : 'expired'),
                'recent' => $myCall->created_at->gt(now()->subMinutes(GuestWaiterCall::EXPIRES_AFTER_MINUTES)),
            ] : null,
            // Polling speeds up while anything here can still change.
            'live' => $waiting !== []
                || $unpaid->contains(fn (Order $o) => $o->status !== 'served')
                || $openClaims->isNotEmpty()
                || $callLive,
        ];
    }

    /**
     * What the guest sees per request line (Phase 3 mapping, D6), and
     * whether it can still change.
     *
     * @return array{0: string, 1: bool}
     */
    public static function lineStatus(GuestRequestItem $item): array
    {
        // Rooms (Phase 5): once made, the porter's progress is what matters.
        if ($item->delivery_status) {
            return match ($item->delivery_status) {
                GuestRequestItem::AWAITING_DISPATCH => ['Ready', false],
                GuestRequestItem::OUT_FOR_DELIVERY => [$item->porter ? 'On the way with '.GuestShifts::firstName($item->porter) : 'On the way', false],
                GuestRequestItem::DELIVERED => ['Delivered', true],
                GuestRequestItem::REFUSED => self::refusalOpen($item) ? ['Refused — being reviewed', false] : ['Refused — removed from your bill', true],
                default => [ucfirst($item->delivery_status), true],
            };
        }

        return match ($item->status) {
            'pending' => [$item->request?->isRoom() ? 'Waiting for reception' : 'Waiting for waiter', false],
            'at_bar' => ['At the bar', false],
            'needs_waiter' => ['Finding a waiter', false],
            'released' => ['Ready', true], // D33: the bar's one Mark Ready
            'removed' => ['Unavailable: '.self::reason($item), true],
            'cancelled' => ['Cancelled', true],
            'ordered' => in_array($item->order?->status, ['pending', 'preparing'], true)
                ? ['Preparing', false]
                : ['Ready', true],
            default => [ucfirst($item->status), true],
        };
    }

    private static function refusalOpen(GuestRequestItem $item): bool
    {
        return GuestDeliveryRefusal::where('guest_request_id', $item->guest_request_id)
            ->whereJsonContains('line_ids', $item->id)
            ->whereIn('status', [GuestDeliveryRefusal::AWAITING_BAR_RETURN, GuestDeliveryRefusal::AWAITING_MANAGER])
            ->exists();
    }

    /**
     * A room's live bill (Phase 5, D25): the stay's folio — charges (room
     * orders shown item by item), payments — plus guest lines not charged
     * yet (greyed) and refused deliveries still being reviewed. Same shape
     * as a table's bill, so the page shows both the same way. ONLY for a
     * trusted phone; the caller checks.
     *
     * @return array<string, mixed>
     */
    public function forStay(Booking $stay, string $deviceId): array
    {
        $lines = FolioLine::with(['order.items', 'reversal'])
            ->where('folio_id', $stay->folio?->id ?? 0)
            ->orderBy('created_at')->orderBy('id')
            ->get();
        $requestLines = GuestRequestItem::with(['request', 'order', 'porter'])
            ->whereHas('request', fn ($q) => $q->where('stay_id', $stay->id))
            ->get();
        $byOrderItem = $requestLines->whereNotNull('order_item_id')->keyBy('order_item_id');

        $onBill = [];
        $paid = [];
        $unavailable = [];

        foreach ($lines as $line) {
            if ($line->isReversal()) {
                continue;
            }

            $voided = $line->reversal !== null;

            if ($line->type === 'payment') {
                if (! $voided) {
                    $paid[] = $this->line('Payment ('.str_replace('_', ' ', (string) $line->payment_method).')', 1, -(float) $line->amount, null, null) + ['status_label' => 'Paid'];
                }

                continue;
            }

            $items = $line->type === 'order' && $line->order ? $line->order->items : collect();

            if ($items->isEmpty()) {
                $entry = $this->line($line->type === 'order' ? 'Room order' : (string) $line->description, 1, (float) $line->amount, null, null);
                $voided ? $unavailable[] = $entry + ['status_label' => 'Cancelled', 'reason' => 'Cancelled'] : $onBill[] = $entry + ['status_label' => 'Charged'];

                continue;
            }

            foreach ($items as $item) {
                $entry = $this->line($item->product_name, (int) $item->quantity, (float) $item->unit_price, $item->chips, $item->note);
                $guestLine = $byOrderItem->get($item->id);

                if ($voided) {
                    $unavailable[] = $entry + [
                        'status_label' => 'Removed from your bill',
                        'reason' => $guestLine?->delivery_status === GuestRequestItem::REFUSED ? 'Refused' : 'Cancelled',
                    ];
                } elseif ((int) $item->quantity > 0) {
                    $onBill[] = $entry + ['status_label' => $guestLine ? self::lineStatus($guestLine)[0] : 'Charged'];
                }
            }
        }

        $waiting = $requestLines
            ->filter(fn ($l) => in_array($l->status, ['pending', 'at_bar'], true)
                && in_array($l->request->status, [GuestRequest::STATUS_PENDING, GuestRequest::STATUS_CONFIRMED], true))
            ->map(fn ($l) => $this->line($l->name_snapshot, $l->finalQuantity(), (float) $l->unit_price_snapshot, $l->chips, $l->note)
                + ['status_label' => self::lineStatus($l)[0]])
            ->values()->all();

        foreach ($requestLines as $l) {
            if ($l->status === 'removed' || ($l->status === 'cancelled' && $l->removed_reason)) {
                $unavailable[] = $this->line($l->name_snapshot, $l->quantity_requested, (float) $l->unit_price_snapshot, $l->chips, $l->note)
                    + ['status_label' => 'Unavailable', 'reason' => $l->removed_reason === 'checked_out' ? 'Stay ended' : self::reason($l)];
            }
        }

        $charges = round($lines->where('type', '!=', 'payment')->sum(fn ($l) => (float) $l->amount), 2);
        $paidTotal = round(-$lines->where('type', 'payment')->sum(fn ($l) => (float) $l->amount), 2);
        $balance = max(0, $charges - $paidTotal);
        $openClaims = GuestPaymentClaim::where('stay_id', $stay->id)->open()->get();
        $claimed = round((float) $openClaims->sum('amount'), 2);

        $myClaims = GuestPaymentClaim::with('transferAccount')->where('stay_id', $stay->id)->where('device_id', $deviceId)->latest('id')->get();
        $myCall = GuestWaiterCall::where('room_id', $stay->room_id)->where('device_id', $deviceId)->latest('id')->first();
        $callLive = $myCall && $myCall->status === GuestWaiterCall::STATUS_OPEN && $myCall->created_at->gt(now()->subMinutes(GuestWaiterCall::EXPIRES_AFTER_MINUTES));

        return [
            'totals' => [
                'bill' => $this->money($charges),
                'paid' => $this->money($paidTotal),
                'claimed' => $this->money($claimed),
                'remaining' => $this->money(max(0, $balance - $claimed)),
            ],
            'tracker' => $this->tracker(GuestRequest::where('stay_id', $stay->id), $deviceId),
            'sections' => [
                'on_bill' => $onBill,
                'waiting' => $waiting,
                'unavailable' => $unavailable,
                'paid' => $paid,
            ],
            'claims' => $myClaims->map(fn (GuestPaymentClaim $c) => [
                'id' => $c->id,
                'payer_name' => $c->payer_name,
                'amount' => $this->money((float) $c->amount),
                'account' => $c->transferAccount?->bank_name,
                'status' => $c->status,
                'status_label' => match ($c->status) {
                    GuestPaymentClaim::STATUS_OPEN => 'Claim sent — reception will confirm.',
                    GuestPaymentClaim::STATUS_WITHDRAWN => 'Withdrawn',
                    GuestPaymentClaim::STATUS_MATCHED => 'Received by reception',
                    default => 'Not received — please speak to reception',
                },
            ])->values()->all(),
            'call' => $myCall ? [
                'reason' => $myCall->label(),
                'status' => $callLive ? 'open' : ($myCall->status === GuestWaiterCall::STATUS_ACKNOWLEDGED ? 'acknowledged' : 'expired'),
                'recent' => $myCall->created_at->gt(now()->subMinutes(GuestWaiterCall::EXPIRES_AFTER_MINUTES)),
            ] : null,
            'live' => $waiting !== []
                || $requestLines->contains(fn ($l) => in_array($l->delivery_status, [GuestRequestItem::AWAITING_DISPATCH, GuestRequestItem::OUT_FOR_DELIVERY], true)
                    || ($l->status === 'ordered' && ! $l->delivery_status))
                || $openClaims->isNotEmpty()
                || $callLive,
        ];
    }

    private static function reason(GuestRequestItem $item): string
    {
        return preg_replace('/^Reduced to \d+: /', '', (string) $item->removed_reason) ?: 'Unavailable';
    }

    private static function orderStatusLabel(Order $order): string
    {
        return match ($order->status) {
            'pending', 'preparing' => $order->destination === 'bar' ? 'On the way' : 'Preparing',
            'ready' => 'Ready',
            'served' => 'Served',
            default => ucfirst($order->status),
        };
    }

    /**
     * The stepper for this phone's latest request (or the table's, if this
     * phone hasn't sent one): food and drinks each sit on one step.
     *
     * @return array<string, mixed>|null
     */
    private function tracker(Builder $sittingRequests, ?string $deviceId): ?array
    {
        $requests = $sittingRequests->with(['items.order'])
            ->whereIn('status', [GuestRequest::STATUS_PENDING, GuestRequest::STATUS_CONFIRMED])
            ->latest('id');

        $request = ($deviceId ? (clone $requests)->where('device_id', $deviceId)->first() : null) ?? $requests->first();

        if (! $request) {
            return null;
        }

        $step = function (Collection $lines) use ($request): ?int {
            $lines = $lines->whereNotIn('status', ['removed', 'cancelled']);

            if ($lines->isEmpty()) {
                return null;
            }

            if ($request->isPending()) {
                return 0;
            }

            // The slowest line decides where the dot sits.
            return $lines->map(fn (GuestRequestItem $l) => match (true) {
                // Rooms (Phase 5): out with a porter is "on the way", delivered is done.
                in_array($l->delivery_status, [GuestRequestItem::DELIVERED], true) => 4,
                $l->delivery_status === GuestRequestItem::OUT_FOR_DELIVERY => 3,
                default => match ($l->status) {
                    'pending' => 1,
                    'at_bar', 'needs_waiter' => 2,
                    'released' => 4, // D33: marked ready at the bar
                    'ordered' => in_array($l->order?->status, ['pending', 'preparing'], true) ? 2 : 4,
                    default => 1,
                },
            })->min();
        };

        return [
            'ref' => $request->ref,
            'steps' => self::TRACKER_STEPS,
            'food' => $step($request->items->where('station', GuestStation::KITCHEN)),
            'drinks' => $step($request->items->where('station', GuestStation::BAR)),
        ];
    }

    /** @return array<string, mixed> */
    private function line(?string $name, int $qty, float $price, mixed $chips, ?string $note): array
    {
        return [
            'name' => (string) $name,
            'qty' => $qty,
            'chips' => is_array($chips) ? array_values($chips) : [],
            'note' => $note,
            'price' => $this->money($price),
            'total' => $this->money($price * $qty),
        ];
    }

    private function money(float $naira): int
    {
        return (int) round($naira);
    }
}
