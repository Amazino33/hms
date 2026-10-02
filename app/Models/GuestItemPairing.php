<?php

namespace App\Models;

use App\Services\Guest\GuestMenuService;
use App\Support\GuestMenuOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "Goes well with" (Phase 7C, D38): up to 3 owner-picked pairings per menu
 * item or product, in order. Written only through syncFor().
 */
class GuestItemPairing extends Model
{
    public const MAX = 3;

    protected $guarded = [];

    /** @return list<string> the paired item keys, in order */
    public static function keysFor(Model $item): array
    {
        return static::where('item_type', $item instanceof MenuItem ? 'menu_item' : 'product')
            ->where('item_id', $item->getKey())
            ->orderBy('sort')
            ->get()
            ->map(fn (self $p) => ($p->pair_type === 'menu_item' ? 'm' : 'p').$p->pair_id)
            ->all();
    }

    /**
     * Replace an item's pairings with these keys, in this order.
     *
     * @param  array<int, string>  $keys
     *
     * @throws \InvalidArgumentException with a message for the admin form
     */
    public static function syncFor(Model $item, array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys)));
        $own = GuestMenuOptions::keyFor($item);

        if (count($keys) > self::MAX) {
            throw new \InvalidArgumentException('Pick at most '.self::MAX.' items that go well with this one.');
        }

        if (in_array($own, $keys, true)) {
            throw new \InvalidArgumentException('An item can\'t go well with itself.');
        }

        $pairs = [];

        foreach ($keys as $sort => $key) {
            $parsed = GuestMenuOptions::parseKey($key);

            if (! $parsed || ! GuestMenuOptions::find($key)) {
                throw new \InvalidArgumentException('One of the "goes well with" items no longer exists.');
            }

            $pairs[] = ['pair_type' => $parsed[0], 'pair_id' => $parsed[1], 'sort' => $sort];
        }

        $itemType = $item instanceof MenuItem ? 'menu_item' : 'product';

        DB::transaction(function () use ($itemType, $item, $pairs) {
            static::where('item_type', $itemType)->where('item_id', $item->getKey())->delete();

            foreach ($pairs as $pair) {
                static::create($pair + ['item_type' => $itemType, 'item_id' => $item->getKey()]);
            }
        });

        GuestMenuService::forgetMenuCache();
    }

    /** @return array<string, list<string>> every item's pairings, keyed by item key */
    public static function allKeyed(): array
    {
        return static::orderBy('sort')->get()
            ->groupBy(fn (self $p) => ($p->item_type === 'menu_item' ? 'm' : 'p').$p->item_id)
            ->map(fn ($group) => $group->map(fn (self $p) => ($p->pair_type === 'menu_item' ? 'm' : 'p').$p->pair_id)->values()->all())
            ->all();
    }
}
