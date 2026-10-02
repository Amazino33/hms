<?php

namespace App\Support;

use App\Models\MenuItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared vocabulary for the guest menu's selling features (Phase 7C, D38):
 * badges, the ways a cart line can be added, and the "m12" / "p5" item
 * keys the guest page uses for menu items and products.
 */
class GuestMenuOptions
{
    public const BADGES = [
        'chefs_special' => "Chef's special",
        'bestseller' => 'Bestseller',
        'new' => 'New',
        'spicy' => 'Spicy',
    ];

    /** How a guest line was added — a label for reports, never a rule. */
    public const ADDED_VIA = ['menu', 'search', 'recommended', 'pairing', 'addon', 'round', 'again'];

    public static function keyFor(Model $item): string
    {
        return ($item instanceof MenuItem ? 'm' : 'p').$item->getKey();
    }

    /**
     * @return array{0: string, 1: int}|null [item_type, id] for "m12" / "p5"
     */
    public static function parseKey(?string $key): ?array
    {
        if (! is_string($key) || ! preg_match('/^([mp])(\d+)$/', $key, $m)) {
            return null;
        }

        return [$m[1] === 'm' ? 'menu_item' : 'product', (int) $m[2]];
    }

    public static function find(string $key): ?Model
    {
        $parsed = self::parseKey($key);

        if (! $parsed) {
            return null;
        }

        return $parsed[0] === 'menu_item' ? MenuItem::find($parsed[1]) : Product::find($parsed[1]);
    }

    /**
     * Every menu item and active product, for the admin pickers:
     * ["m12" => "Jollof Rice (kitchen)", "p5" => "Star Beer (bar)"].
     *
     * @return array<string, string>
     */
    public static function itemOptions(): array
    {
        $options = [];

        foreach (MenuItem::orderBy('name')->get(['id', 'name']) as $item) {
            $options['m'.$item->id] = $item->name.' (kitchen)';
        }

        foreach (Product::where('is_active', true)->orderBy('name')->get(['id', 'name']) as $product) {
            $options['p'.$product->id] = $product->name.' (bar)';
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
