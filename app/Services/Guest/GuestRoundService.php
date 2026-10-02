<?php

namespace App\Services\Guest;

use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTableSession;
use App\Models\MenuItem;
use App\Models\Product;

/**
 * "Another round" (Phase 4, D21). Read-only: it builds cart lines from this
 * phone's last released drinks for the guest to review — it never sends
 * anything. Items that can't be ordered now are skipped and named.
 */
class GuestRoundService
{
    public function __construct(private readonly GuestMenuService $menu = new GuestMenuService) {}

    /**
     * @return array{lines: list<array<string, mixed>>, skipped: list<string>}
     */
    public function lastRound(GuestTableSession|Booking $sitting, string $deviceId): array
    {
        // A table sitting, or a room's stay (Phase 5).
        $request = GuestRequest::where($sitting instanceof Booking ? 'stay_id' : 'guest_table_session_id', $sitting->id)
            ->where('device_id', $deviceId)
            ->whereHas('items', fn ($q) => $q->where('station', GuestStation::BAR)->where('status', 'released'))
            ->latest('id')
            ->first();

        if (! $request) {
            return ['lines' => [], 'skipped' => []];
        }

        $lines = [];
        $skipped = [];

        $released = $request->items()->where('station', GuestStation::BAR)->where('status', 'released')->orderBy('id')->get();

        foreach ($released as $item) {
            /** @var GuestRequestItem $item */
            $model = $item->item_type === 'menu_item' ? MenuItem::find($item->item_id) : Product::find($item->item_id);

            if (! $model || ! $this->menu->isAvailableNow($item->item_type, $item->item_id)) {
                $skipped[] = $item->name_snapshot;

                continue;
            }

            $lines[] = [
                'key' => ($item->item_type === 'menu_item' ? 'm' : 'p').$model->id,
                'type' => $item->item_type,
                'id' => $model->id,
                'name' => $model->name,
                // Today's price: the new request snapshots it again at send.
                'price' => (int) round((float) ($item->item_type === 'menu_item' ? $model->sale_price : $model->price)),
                'qty' => $item->finalQuantity(),
                'chips' => $item->chips ?? [],
                'note' => '',
            ];
        }

        return ['lines' => $lines, 'skipped' => array_values(array_unique($skipped))];
    }
}
