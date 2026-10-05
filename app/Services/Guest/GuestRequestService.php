<?php

namespace App\Services\Guest;

use App\Models\Booking;
use App\Models\ChipGroup;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTableSession;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Table;
use App\Models\User;
use App\Services\OrderSplitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of guest requests (Phase 2). A request is what a guest
 * asked for — it creates no order, moves no stock, touches no folio and
 * alerts nobody yet. Staff confirmation (Phase 3) is what commits it.
 *
 * Every refusal is a GuestRequestException carrying a guest-facing message.
 */
class GuestRequestService
{
    public const MAX_LINES = 30;

    public const MAX_QTY = 20;

    public const MAX_PENDING_PER_DEVICE = 3;

    public const MAX_PENDING_PER_TABLE = 10;

    public const NOT_STAYING_MESSAGE = 'Ordering is available during your stay.';

    public const NOTE_MAX = 100;

    public const STALE_AFTER_HOURS = 3;

    public function __construct(private readonly GuestMenuService $menu = new GuestMenuService) {}

    /**
     * @param  array<int, array{type?: string, id?: mixed, qty?: mixed, chips?: mixed, note?: mixed, added_via?: mixed}>  $lines
     * @param  ?string  $channel  rooms: 'whatsapp' when the guest is sending it on WhatsApp too
     *
     * @throws GuestRequestException
     */
    public function submit(string $token, string $deviceId, array $lines, ?string $channel = null): GuestRequest
    {
        $place = QrTokens::resolve($token);

        if (! $place) {
            throw new GuestRequestException('invalid_code', 'This code is no longer valid — please ask a staff member.', 404);
        }

        $prepared = $this->prepareLines($lines);

        if ($place instanceof Room) {
            return $this->submitForRoom($place, $deviceId, $prepared, $channel);
        }

        return DB::transaction(function () use ($place, $deviceId, $prepared) {
            // Serialises every submit for this table — the first two
            // requests of a sitting can't both open a session.
            $table = Table::whereKey($place->id)->lockForUpdate()->firstOrFail();

            $this->assertPendingLimits('table_id', $table->id, $deviceId);

            $session = GuestTableSession::open()->where('table_id', $table->id)->first()
                ?? GuestTableSession::create([
                    'public_id' => Str::random(16),
                    'table_id' => $table->id,
                    'opened_at' => now(),
                    'bill_from_at' => now(),
                    'last_activity_at' => now(),
                ]);

            $ref = GuestRequestRefs::next(GuestRequestRefs::tableCode($table->name));

            $request = GuestRequest::create([
                'ref' => $ref['ref'],
                'business_date' => $ref['business_date'],
                'source' => 'table',
                'table_id' => $table->id,
                'guest_table_session_id' => $session->id,
                'device_id' => $deviceId,
                'status' => GuestRequest::STATUS_PENDING,
                'total_snapshot' => collect($prepared)->sum(fn ($line) => $line['unit_price_snapshot'] * $line['quantity_requested']),
                'submitted_at' => now(),
            ]);

            foreach ($prepared as $line) {
                GuestRequestItem::create($line + ['guest_request_id' => $request->id, 'status' => 'pending']);
            }

            $session->update(['last_activity_at' => now()]);

            return $request->load('items');
        });
    }

    /**
     * A room order (Phase 5): only during a checked-in stay, tied to that
     * stay. No table session — reception approves it on Room Orders. The
     * guest's phone then opens WhatsApp to reception (GuestWhatsapp).
     *
     * @param  list<array<string, mixed>>  $prepared
     *
     * @throws GuestRequestException
     */
    private function submitForRoom(Room $room, string $deviceId, array $prepared, ?string $channel): GuestRequest
    {
        return DB::transaction(function () use ($room, $deviceId, $prepared, $channel) {
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $stay = self::currentStay($room);

            if (! $stay) {
                throw new GuestRequestException('not_staying', self::NOT_STAYING_MESSAGE, 422);
            }

            $this->assertPendingLimits('room_id', $room->id, $deviceId);

            $ref = GuestRequestRefs::next(GuestRequestRefs::roomCode((string) $room->number));
            $channel = $channel === GuestRequest::CHANNEL_WHATSAPP && GuestOrderingSettings::receptionWhatsapp()
                ? GuestRequest::CHANNEL_WHATSAPP
                : GuestRequest::CHANNEL_NONE;

            $request = GuestRequest::create([
                'ref' => $ref['ref'],
                'business_date' => $ref['business_date'],
                'source' => 'room',
                'channel' => $channel,
                'room_id' => $room->id,
                'stay_id' => $stay->id,
                'device_id' => $deviceId,
                'first_from_device' => ! GuestRequest::where('stay_id', $stay->id)->where('device_id', $deviceId)->exists(),
                'status' => GuestRequest::STATUS_PENDING,
                'total_snapshot' => collect($prepared)->sum(fn ($line) => $line['unit_price_snapshot'] * $line['quantity_requested']),
                'submitted_at' => now(),
            ]);

            foreach ($prepared as $line) {
                GuestRequestItem::create($line + ['guest_request_id' => $request->id, 'status' => 'pending']);
            }

            return $request->load('items');
        });
    }

    /** The room's checked-in stay right now, if any (phase-5-verification §4). */
    public static function currentStay(Room $room): ?Booking
    {
        return Booking::where('room_id', $room->id)->currentlyCheckedIn()->first();
    }

    /**
     * A waiter accepts a guest request with their PIN (Phase 3).
     *
     * Food lines become one real kitchen order straight away — created
     * pending, so its stock leaves at Mark Ready like any other. Drink lines
     * go to the bar queue ('at_bar'); their order is created when the
     * bartender releases them (GuestBarReleaseService). The first waiter to
     * accept a sitting becomes its assigned waiter; while that waiter is on
     * an active shift, only they may accept the table's later requests (D15).
     *
     * D19: on a sitting's FIRST acceptance, older unpaid orders on the table
     * need an answer to "Same guests?" — yes moves bill_from_at back to the
     * oldest of them; no leaves it (they are settled separately). Without
     * an answer, SameGuestsQuestion is thrown and nothing changes.
     *
     * @throws SameGuestsQuestion
     * @throws \Exception with a message written for the waiter
     */
    public function confirm(GuestRequest $request, User $waiter, ?bool $sameGuests = null): GuestRequest
    {
        return DB::transaction(function () use ($request, $waiter, $sameGuests) {
            $request = GuestRequest::lockForUpdate()->findOrFail($request->id);

            if ($request->isRoom()) {
                throw new \Exception('Room orders are approved by reception, on the Room Orders page.');
            }

            if (! $request->isPending()) {
                throw new \Exception("Order {$request->ref} has already been handled — it is {$request->statusLabel()}.");
            }

            $session = GuestTableSession::lockForUpdate()->find($request->guest_table_session_id);

            if (! $session?->isOpen()) {
                throw new \Exception('That table\'s guest session has closed. Ask the guest to send the order again.');
            }

            $shift = GuestShifts::activeWaiterShift($waiter);

            if (! $shift) {
                throw new \Exception('Start your waiter shift before accepting guest orders.');
            }

            $this->assertMayServe($session, $waiter);

            $earlier = self::earlierUnpaid($session);

            if ($earlier && $sameGuests === null) {
                throw new SameGuestsQuestion($earlier['total'], (string) $session->table?->name);
            }

            if ($earlier && $sameGuests === true) {
                $session->update(['bill_from_at' => $earlier['oldest']]);
            }

            if ($session->assigned_waiter_user_id !== $waiter->id || $session->assigned_shift_id !== $shift->id) {
                $session->update(['assigned_waiter_user_id' => $waiter->id, 'assigned_shift_id' => $shift->id]);
            }

            $request->update([
                'status' => GuestRequest::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $waiter->id,
            ]);

            $lines = $request->items()->where('status', 'pending')->get();
            $food = $lines->where('station', GuestStation::KITCHEN);

            if ($food->isNotEmpty()) {
                self::placeOrders($food, $request, $waiter, $shift);
                GuestRequestItem::whereKey($food->pluck('id'))->update(['status' => 'ordered']);
            }

            GuestRequestItem::whereKey($lines->where('station', GuestStation::BAR)->pluck('id'))->update(['status' => 'at_bar']);

            $session->update(['last_activity_at' => now()]);

            return $request->fresh('items');
        });
    }

    /**
     * D19: unpaid orders on the table from before this sitting opened, but
     * only while the sitting has never been accepted — after that the
     * question has been answered.
     *
     * @return array{total: float, oldest: \Carbon\CarbonInterface}|null
     */
    public static function earlierUnpaid(GuestTableSession $session): ?array
    {
        $accepted = GuestRequest::where('guest_table_session_id', $session->id)->whereNotNull('confirmed_at')->exists();

        if ($accepted) {
            return null;
        }

        $orders = \App\Models\Order::where('table_id', $session->table_id)
            ->where('created_at', '<', $session->opened_at)
            ->whereIn('status', GuestBillService::UNPAID)
            ->where(fn ($q) => $q->where('is_return', false)->orWhereNull('is_return'))
            ->get()
            // Only orders that still owe money. A "served" order that was
            // paid in full (or came to ₦0) is not unpaid — asking about it
            // left the kiosk unable to accept the table at all.
            ->filter(fn ($o) => (float) $o->total_amount - (float) $o->amount_paid > 0);

        if ($orders->isEmpty()) {
            return null;
        }

        return [
            'total' => round($orders->sum(fn ($o) => (float) $o->total_amount - (float) $o->amount_paid), 2),
            'oldest' => $orders->min('created_at'),
        ];
    }

    /**
     * Lines the bar sent back because the assigned waiter was off shift
     * (D3 safety net) go back into the bar queue under whichever waiter
     * picks them up — who then becomes the table's waiter.
     *
     * @throws \Exception
     */
    public function reacceptReturned(GuestRequest $request, User $waiter): GuestRequest
    {
        return DB::transaction(function () use ($request, $waiter) {
            $request = GuestRequest::lockForUpdate()->findOrFail($request->id);
            $session = GuestTableSession::lockForUpdate()->find($request->guest_table_session_id);
            $returned = $request->items()->where('status', 'needs_waiter')->get();

            if ($returned->isEmpty()) {
                throw new \Exception("Nothing from {$request->ref} is waiting for a waiter any more.");
            }

            $shift = GuestShifts::activeWaiterShift($waiter);

            if (! $shift) {
                throw new \Exception('Start your waiter shift before taking over a guest order.');
            }

            $this->assertMayServe($session, $waiter);

            $session->update(['assigned_waiter_user_id' => $waiter->id, 'assigned_shift_id' => $shift->id, 'last_activity_at' => now()]);
            GuestRequestItem::whereKey($returned->pluck('id'))->update(['status' => 'at_bar']);

            return $request->fresh('items');
        });
    }

    /**
     * Staff take back what hasn't become an order yet — drinks still at the
     * bar or waiting for a waiter. Free: no stock or order exists for them.
     * Food already ordered is left alone (that's the normal void).
     *
     * @throws \Exception
     */
    public function cancelByStaff(GuestRequest $request, User $actor, string $reason): GuestRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('Say why the guest order is being cancelled.');
        }

        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = GuestRequest::lockForUpdate()->findOrFail($request->id);

            if ($request->isRoom()) {
                throw new \Exception('Room orders are handled by reception, on the Room Orders page.');
            }

            $session = $request->session;

            $isAssignedWaiter = $session && $session->assigned_waiter_user_id === $actor->id;

            if (! $isAssignedWaiter && ! $actor->hasRole(['manager', 'admin', 'super_admin'])) {
                throw new \Exception('Only the table\'s waiter or a manager can cancel a guest order.');
            }

            $waiting = $request->items()->whereIn('status', GuestRequestItem::WAITING)->get();

            if ($waiting->isEmpty()) {
                throw new \Exception('Nothing on this order can be cancelled here — food already sent to the kitchen uses the normal void.');
            }

            GuestRequestItem::whereKey($waiting->pluck('id'))->update(['status' => 'cancelled', 'removed_reason' => $reason]);
            self::closeIfNothingLeft($request, $reason);

            return $request->fresh('items');
        });
    }

    /**
     * Turns guest request lines into a real order through OrderSplitter —
     * the ONLY place a guest line becomes an order, for both the waiter's
     * accept (food) and the bartender's release (drinks). Every line carries
     * its own item identity, the price the guest was shown (D18), its chip
     * labels and its note; the order goes under the waiter's identity and
     * shift (D2), and is linked back to each line.
     *
     * A ROOM request (Phase 5) becomes a room order instead, through
     * RoomOrderService::placeOrder(): billed to the stay's folio with the
     * request ref on the charge, under reception's identity, no shift.
     *
     * @param  \Illuminate\Support\Collection<int, GuestRequestItem>  $lines
     * @return array<int, \App\Models\Order>
     *
     * @throws \Exception
     */
    public static function placeOrders($lines, GuestRequest $request, User $waiter, ?\App\Models\Shift $shift): array
    {
        $cart = [];

        foreach ($lines as $line) {
            $cart['g'.$line->id] = [
                'name' => $line->name_snapshot,
                'price' => (float) $line->unit_price_snapshot,
                'quantity' => $line->finalQuantity(),
                'line_type' => $line->item_type,
                'line_id' => $line->item_id,
                'unit_price_override' => (float) $line->unit_price_snapshot,
                'chips' => $line->chips ?? [],
                'note' => $line->note,
            ];
        }

        if ($request->isRoom()) {
            $stay = Booking::find($request->stay_id);

            if (! $stay || ! $stay->isCheckedIn()) {
                throw new \Exception("Room {$request->room?->number}'s guest has checked out — {$request->ref} can't be billed any more.");
            }

            $rooms = new \App\Services\RoomOrderService;
            $orders = $rooms->placeOrder($stay->room_id, $cart, $waiter->id, [
                'payment_method' => 'cash',
                'source' => OrderSplitter::SOURCE_GUEST_REQUEST,
            ], $request->ref);

            if (collect($orders)->contains(fn ($order) => $order->booking_id !== $stay->id)) {
                throw new \Exception("{$request->ref} belongs to a stay that is no longer in this room.");
            }

            $lineItemIds = $rooms->lastLineItemIds;
        } else {
            $splitter = new OrderSplitter;
            $orders = $splitter->handle($cart, $request->table_id, $waiter->id, [
                'status' => 'pending',
                'payment_method' => 'cash',
                'shift_id' => $shift?->id,
                'source' => OrderSplitter::SOURCE_GUEST_REQUEST,
            ]);

            $lineItemIds = $splitter->lastLineItemIds;
        }

        $orderIdByItem = \App\Models\OrderItem::whereKey(array_values($lineItemIds))->pluck('order_id', 'id');

        foreach ($lines as $line) {
            $orderItemId = $lineItemIds['g'.$line->id] ?? null;

            GuestRequestItem::whereKey($line->id)->update([
                'order_item_id' => $orderItemId,
                'order_id' => $orderItemId ? $orderIdByItem[$orderItemId] : null,
            ]);
        }

        return $orders;
    }

    /**
     * When nothing on a confirmed request will ever become an order — every
     * line removed or cancelled — the request itself is cancelled by staff.
     */
    public static function closeIfNothingLeft(GuestRequest $request, string $reason): void
    {
        $live = $request->items()->whereNotIn('status', ['removed', 'cancelled'])->exists();

        if (! $live) {
            $request->update([
                'status' => GuestRequest::STATUS_CANCELLED_BY_STAFF,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
        }
    }

    /**
     * D15: while the sitting's assigned waiter is on an active shift, only
     * they may take its requests.
     *
     * @throws \Exception
     */
    private function assertMayServe(?GuestTableSession $session, User $waiter): void
    {
        $assigned = $session?->assignedWaiter;

        if ($assigned && $assigned->id !== $waiter->id && GuestShifts::activeWaiterShift($assigned)) {
            throw new \Exception('This is '.GuestShifts::firstName($assigned).'\'s table — only they can accept its orders while on shift.');
        }
    }

    /**
     * @throws GuestRequestException
     */
    public function cancelByGuest(GuestRequest $request, string $deviceId): GuestRequest
    {
        return DB::transaction(function () use ($request, $deviceId) {
            $request = GuestRequest::lockForUpdate()->findOrFail($request->id);

            if (! hash_equals($request->device_id, $deviceId)) {
                throw new GuestRequestException('not_yours', 'You can only cancel orders sent from this phone.', 403);
            }

            if (! $request->isPending()) {
                throw new GuestRequestException('not_pending', 'This order can no longer be cancelled here — please ask your waiter.', 409);
            }

            $request->update([
                'status' => GuestRequest::STATUS_CANCELLED_BY_GUEST,
                'cancelled_at' => now(),
                'cancel_reason' => 'Cancelled by guest',
            ]);

            $request->items()->update(['status' => 'cancelled']);
            $request->session?->update(['last_activity_at' => now()]);

            return $request->fresh('items');
        });
    }

    /**
     * guest:expire-stale (D14): pending requests older than 3 hours expire;
     * open sessions with nothing live, nothing unpaid (D20) and no activity
     * for 3 hours close — through TableCloseService; waiter calls older than
     * 15 minutes expire (D22).
     *
     * @return array{expired: int, closed: int, calls: int}
     */
    public function expireStale(): array
    {
        $cutoff = now()->subHours(self::STALE_AFTER_HOURS);
        $expired = 0;

        GuestRequest::where('status', GuestRequest::STATUS_PENDING)
            ->where('submitted_at', '<=', $cutoff)
            ->each(function (GuestRequest $request) use (&$expired) {
                DB::transaction(function () use ($request) {
                    $request->update(['status' => GuestRequest::STATUS_EXPIRED]);
                    $request->items()->where('status', 'pending')->update(['status' => 'cancelled']);
                });
                $expired++;
            });

        $closed = (new TableCloseService)->closeStale();
        $calls = (new GuestWaiterCallService)->expireStale();

        return ['expired' => $expired, 'closed' => $closed, 'calls' => $calls];
    }

    /**
     * Validates and snapshots every line before anything is written.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GuestRequestException
     */
    private function prepareLines(array $lines): array
    {
        $lines = array_values($lines);

        if (count($lines) < 1 || count($lines) > self::MAX_LINES) {
            throw new GuestRequestException('bad_lines', 'Add between 1 and '.self::MAX_LINES.' items to your order.');
        }

        $prepared = [];

        foreach ($lines as $line) {
            $type = $line['type'] ?? null;
            $id = filter_var($line['id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($line['qty'] ?? null, FILTER_VALIDATE_INT);

            if (! in_array($type, ['menu_item', 'product'], true) || $id === false) {
                throw new GuestRequestException('bad_item', 'Something in your order isn\'t on the menu. Please refresh the menu and try again.');
            }

            if ($qty === false || $qty < 1 || $qty > self::MAX_QTY) {
                throw new GuestRequestException('bad_qty', 'Each item can be ordered from 1 to '.self::MAX_QTY.' at a time.');
            }

            $model = $type === 'menu_item'
                ? MenuItem::with('category')->find($id)
                : Product::with('category')->where('is_active', true)->find($id);
            $station = $model instanceof Product ? GuestStation::forProduct($model) : ($model ? GuestStation::KITCHEN : null);

            if (! $model || ! $station) {
                throw new GuestRequestException('bad_item', 'Something in your order isn\'t on the menu. Please refresh the menu and try again.');
            }

            $key = ($type === 'menu_item' ? 'm' : 'p').$model->id;

            if (! $this->menu->isAvailableNow($type, $model->id)) {
                throw new GuestRequestException('unavailable', "Sorry, {$model->name} just sold out — we've removed it from your order.", 422, ['item' => $key, 'name' => $model->name]);
            }

            // Phase 7C (D38): how the guest added it — a label for the owner's
            // reports only. It never changes price, routing, stock or rules.
            $addedVia = $line['added_via'] ?? 'menu';

            if (! is_string($addedVia) || ! in_array($addedVia, \App\Support\GuestMenuOptions::ADDED_VIA, true)) {
                throw new GuestRequestException('bad_added_via', 'Something in your order isn\'t right. Please refresh the menu and try again.');
            }

            $prepared[] = [
                'added_via' => $addedVia,
                'item_type' => $type,
                'item_id' => $model->id,
                'station' => $station,
                'name_snapshot' => $model->name,
                // The price lock: what the guest saw is what they pay.
                'unit_price_snapshot' => round((float) ($type === 'menu_item' ? $model->sale_price : $model->price), 2),
                'quantity_requested' => $qty,
                'chips' => $this->validChips($model->category_id, $line['chips'] ?? []),
                'note' => $this->cleanNote($line['note'] ?? null),
            ];
        }

        return $prepared;
    }

    /**
     * @return list<string>|null the chosen labels — snapshots, never ids
     *
     * @throws GuestRequestException
     */
    private function validChips(?int $categoryId, mixed $chosen): ?array
    {
        if (! is_array($chosen) || $chosen === []) {
            return null;
        }

        $chosen = array_values(array_unique(array_map('strval', $chosen)));

        $groups = $categoryId
            ? ChipGroup::where('active', true)
                ->whereHas('categories', fn ($q) => $q->whereKey($categoryId))
                ->with(['options' => fn ($q) => $q->where('active', true)])
                ->get()
            : collect();

        foreach ($chosen as $label) {
            $group = $groups->first(fn ($g) => $g->options->contains('label', $label));

            if (! $group) {
                throw new GuestRequestException('bad_chips', "\"{$label}\" isn't an option for that item. Please pick again.");
            }
        }

        foreach ($groups->where('selection', 'single') as $group) {
            if (count(array_intersect($chosen, $group->options->pluck('label')->all())) > 1) {
                throw new GuestRequestException('bad_chips', "Pick just one {$group->name} option.");
            }
        }

        return $chosen;
    }

    /**
     * @throws GuestRequestException
     */
    private function cleanNote(mixed $note): ?string
    {
        if ($note === null || $note === '') {
            return null;
        }

        $note = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $note)));

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new GuestRequestException('bad_note', 'Notes can be at most '.self::NOTE_MAX.' characters.');
        }

        return $note === '' ? null : $note;
    }

    /**
     * @throws GuestRequestException
     */
    private function assertPendingLimits(string $placeColumn, int $placeId, string $deviceId): void
    {
        $pending = GuestRequest::where('status', GuestRequest::STATUS_PENDING);
        $who = $placeColumn === 'room_id' ? 'reception' : 'a waiter';

        if ((clone $pending)->where('device_id', $deviceId)->count() >= self::MAX_PENDING_PER_DEVICE) {
            throw new GuestRequestException('too_many_pending', 'You already have '.self::MAX_PENDING_PER_DEVICE." orders waiting for {$who}. Please wait for one to be confirmed.", 429);
        }

        if ((clone $pending)->where($placeColumn, $placeId)->count() >= self::MAX_PENDING_PER_TABLE) {
            throw new GuestRequestException('table_busy', $placeColumn === 'room_id'
                ? 'This room already has several orders waiting. Reception will be with you shortly.'
                : 'This table already has several orders waiting. A waiter will be with you shortly.', 429);
        }
    }
}
