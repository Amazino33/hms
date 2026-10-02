<?php

namespace App\Services\Guest;

use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Table;

/**
 * Per-phone guest menu state (Phase 7C, D36/D38). Read-only, and always
 * scoped to ONE device id — never another phone's orders, never another
 * table's.
 *
 *   status()     the latest order's progress, for the status strip
 *   lastRound()  the latest drinks marked ready, for "Another round?"
 *   lastVisit()  what this phone had last time, for "Order again"
 */
class GuestVisitService
{
    public const LAST_VISIT_DAYS = 30;

    public function __construct(private readonly GuestMenuService $menu = new GuestMenuService) {}

    /**
     * @return array{latest_status: ?string, accepted_by_first_name: ?string}
     */
    public function status(Table|Room $place, string $deviceId): array
    {
        $request = $this->currentRequests($place, $deviceId)
            ->whereNotIn('status', [GuestRequest::STATUS_CANCELLED_BY_GUEST, GuestRequest::STATUS_CANCELLED_BY_STAFF, GuestRequest::STATUS_EXPIRED])
            ->with(['items.order', 'confirmedBy'])
            ->latest('id')
            ->first();

        if (! $request) {
            return ['latest_status' => null, 'accepted_by_first_name' => null];
        }

        return [
            'latest_status' => $this->statusOf($request),
            'accepted_by_first_name' => $request->confirmed_at && ! $request->isRoom()
                ? GuestShifts::firstName($request->confirmedBy)
                : null,
        ];
    }

    /** waiting → accepted → preparing / ready → delivered (rooms). */
    public function statusOf(GuestRequest $request): ?string
    {
        if ($request->isPending()) {
            return 'waiting';
        }

        if ($request->status !== GuestRequest::STATUS_CONFIRMED) {
            return null;
        }

        $lines = $request->items->whereNotIn('status', ['removed', 'cancelled']);

        if ($lines->isEmpty()) {
            return null;
        }

        if ($lines->every(fn (GuestRequestItem $l) => $l->delivery_status === GuestRequestItem::DELIVERED)) {
            return 'delivered';
        }

        $ready = fn (GuestRequestItem $l) => $l->status === 'released'
            || ($l->status === 'ordered' && ! in_array($l->order?->status, ['pending', 'preparing'], true));

        if ($lines->every($ready)) {
            return 'ready';
        }

        if ($lines->contains(fn (GuestRequestItem $l) => $l->status === 'ordered' && in_array($l->order?->status, ['pending', 'preparing'], true))) {
            return 'preparing';
        }

        return 'accepted';
    }

    /**
     * This phone's latest drinks that were marked ready, with when.
     *
     * @return array<string, mixed>|null
     */
    public function lastRound(Table|Room $place, string $deviceId): ?array
    {
        $request = $this->currentRequests($place, $deviceId)
            ->whereHas('items', fn ($q) => $q->where('station', GuestStation::BAR)->where('status', 'released'))
            ->with(['items' => fn ($q) => $q->where('station', GuestStation::BAR)->where('status', 'released')])
            ->latest('id')
            ->first();

        if (! $request) {
            return null;
        }

        // A newer drinks order from this phone since then means the round
        // has already been ordered again.
        $newerDrinks = $this->currentRequests($place, $deviceId)
            ->where('id', '>', $request->id)
            ->whereNotIn('status', [GuestRequest::STATUS_CANCELLED_BY_GUEST, GuestRequest::STATUS_EXPIRED])
            ->whereHas('items', fn ($q) => $q->where('station', GuestStation::BAR))
            ->exists();

        $lines = $request->items->map(fn (GuestRequestItem $l) => [
            'name' => $l->name_snapshot,
            'qty' => $l->finalQuantity(),
            'chips' => $l->chips ?? [],
            'price' => (int) round((float) $l->unit_price_snapshot),
        ])->values();

        return [
            'ref' => $request->ref,
            'ready_at' => $request->items->max('released_at')?->toIso8601String(),
            'superseded' => $newerDrinks,
            'lines' => $lines->all(),
            'total' => $lines->sum(fn ($l) => $l['qty'] * $l['price']),
        ];
    }

    /**
     * "Order again": this phone's most recent order from an EARLIER, closed
     * visit (a closed table sitting, or a stay that has checked out) within
     * 30 days — only what is on the menu right now, at today's prices.
     *
     * @return array{summary: string, lines: list<array<string, mixed>>}|null
     */
    public function lastVisit(Table|Room $place, string $deviceId): ?array
    {
        $current = $this->currentRequests($place, $deviceId)->pluck('id');

        $request = GuestRequest::where('device_id', $deviceId)
            ->where('status', GuestRequest::STATUS_CONFIRMED)
            ->where('submitted_at', '>=', now()->subDays(self::LAST_VISIT_DAYS))
            ->whereNotIn('id', $current)
            ->where(fn ($q) => $q
                ->whereHas('session', fn ($s) => $s->whereNotNull('closed_at'))
                ->orWhereHas('stay', fn ($s) => $s->where('status', 'checked_out')))
            ->with('items')
            ->latest('id')
            ->first();

        if (! $request) {
            return null;
        }

        $unavailable = array_flip($this->menu->unavailable());
        $lines = [];

        foreach ($request->items->whereIn('status', ['ordered', 'released']) as $item) {
            $model = $item->item_type === 'menu_item' ? MenuItem::find($item->item_id) : Product::where('is_active', true)->find($item->item_id);
            $key = ($item->item_type === 'menu_item' ? 'm' : 'p').$item->item_id;

            if (! $model || isset($unavailable[$key])) {
                continue;
            }

            $lines[] = [
                'key' => $key,
                'type' => $item->item_type,
                'id' => $model->id,
                'name' => $model->name,
                'price' => (int) round((float) ($item->item_type === 'menu_item' ? $model->sale_price : $model->price)),
                'qty' => $item->finalQuantity(),
                'chips' => $item->chips ?? [],
                'note' => '',
            ];
        }

        if ($lines === []) {
            return null;
        }

        return [
            'summary' => collect($lines)->map(fn ($l) => $l['qty'].'× '.$l['name'])->join(' · '),
            'lines' => $lines,
        ];
    }

    /** This phone's requests in the visit happening now at this place. */
    private function currentRequests(Table|Room $place, string $deviceId)
    {
        $query = GuestRequest::where('device_id', $deviceId);

        if ($place instanceof Room) {
            $stay = Booking::where('room_id', $place->id)->currentlyCheckedIn()->first();

            return $query->where('stay_id', $stay?->id ?? 0);
        }

        $session = \App\Models\GuestTableSession::open()->where('table_id', $place->id)->first();

        return $query->where('guest_table_session_id', $session?->id ?? 0);
    }
}
