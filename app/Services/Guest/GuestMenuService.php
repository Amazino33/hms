<?php

namespace App\Services\Guest;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\MenuPhotoProcessor;
use App\Support\BusinessDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the guest menu page shows (Phase 2), with its own caches —
 * never the POS product caches (D11):
 *
 *   payload()       menu structure, cached 5 min, dropped on any save of a
 *                   menu item, product, category or chip (AppServiceProvider)
 *   unavailable()   item keys that can't be ordered now, cached 30 s
 *   isAvailableNow  the same rule, uncached — what a submit is checked against
 *   popularTonight  top sellers this BusinessDay, cached 10 min
 *
 * Item keys are "m{id}" (menu item) and "p{id}" (product).
 *
 * Availability is the POS's own rule (phase-2-verification.md §2): a menu
 * item is sellable while available_for_sale; a product while it is active
 * and has stock above 0 at the warehouse InventoryService routes it to.
 */
class GuestMenuService
{
    public const PAYLOAD_CACHE_KEY = 'guest_menu:payload';

    public const UNAVAILABLE_CACHE_KEY = 'guest_menu:unavailable';

    public const POPULAR_CACHE_KEY = 'guest_menu:popular:';

    public static function forgetMenuCache(): void
    {
        Cache::forget(self::PAYLOAD_CACHE_KEY);
    }

    /**
     * @return array{tabs: array{drinks: array, food: array}}
     */
    public function payload(): array
    {
        return Cache::remember(self::PAYLOAD_CACHE_KEY, 300, fn () => $this->buildPayload());
    }

    /** @return list<string> */
    public function unavailable(): array
    {
        return Cache::remember(self::UNAVAILABLE_CACHE_KEY, 30, fn () => $this->computeUnavailable());
    }

    public function isAvailableNow(string $type, int $id): bool
    {
        if ($type === 'menu_item') {
            return (bool) MenuItem::whereKey($id)->value('available_for_sale');
        }

        $product = Product::with('category')->where('is_active', true)->find($id);

        return $product && GuestStation::forProduct($product) !== null && $this->productStock($product) > 0;
    }

    /**
     * Up to 8 of tonight's best sellers that can be ordered right now, or
     * nothing at all when fewer than 3 qualify (a row of one or two looks
     * broken, not popular).
     *
     * @return list<array{key: string, qty: int}>
     */
    public function popularTonight(): array
    {
        $businessDate = BusinessDay::today();
        $ranking = Cache::remember(self::POPULAR_CACHE_KEY.$businessDate, 600, fn () => $this->rankSales($businessDate));

        $onMenu = collect($this->payload()['tabs'])->flatten(1)->pluck('items')->flatten(1)->pluck('key')->flip();
        $unavailable = array_flip($this->unavailable());

        $popular = collect($ranking)
            ->filter(fn ($row) => isset($onMenu[$row['key']]) && ! isset($unavailable[$row['key']]))
            ->take(8)
            ->values()
            ->all();

        return count($popular) >= 3 ? $popular : [];
    }

    private function buildPayload(): array
    {
        $chipsByCategory = Category::with(['chipGroups' => fn ($q) => $q->where('active', true)->orderBy('sort_order'), 'chipGroups.options' => fn ($q) => $q->where('active', true)])
            ->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => $category->chipGroups
                ->filter(fn ($group) => $group->options->isNotEmpty())
                ->map(fn ($group) => [
                    'group' => $group->name,
                    'selection' => $group->selection,
                    'options' => $group->options->pluck('label')->values()->all(),
                ])->values()->all()]);

        $items = collect();
        // Phase 7C: owner-picked "goes well with" keys (availability is
        // filtered per request, outside this cache).
        $pairs = \App\Models\GuestItemPairing::allKeyed();

        foreach (Product::with('category')->where('is_active', true)->get() as $product) {
            $station = GuestStation::forProduct($product);

            if ($station) {
                $items->push($this->itemPayload('p', $product, (float) $product->price, $station, $chipsByCategory, $pairs));
            }
        }

        foreach (MenuItem::with('category')->get() as $menuItem) {
            $items->push($this->itemPayload('m', $menuItem, (float) $menuItem->sale_price, GuestStation::KITCHEN, $chipsByCategory, $pairs));
        }

        $tabs = ['drinks' => [], 'food' => []];

        foreach ($items->groupBy('tab') as $tab => $tabItems) {
            $tabs[$tab] = $tabItems
                ->groupBy(fn ($item) => $item['category'] ?? 'Other')
                ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (Collection $sectionItems, string $name) => [
                    'name' => $name,
                    'slug' => \Illuminate\Support\Str::slug($tab.'-'.$name),
                    // The owner's guest_sort first (unset ones after), then name.
                    'items' => $sectionItems
                        ->sortBy([
                            fn ($a, $b) => ($a['sort'] === null) <=> ($b['sort'] === null),
                            fn ($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0),
                            fn ($a, $b) => strnatcasecmp($a['name'], $b['name']),
                        ])
                        ->map(fn ($item) => collect($item)->except(['tab', 'category'])->all())->values()->all(),
                ])
                ->values()
                ->all();
        }

        return ['tabs' => $tabs];
    }

    private function itemPayload(string $prefix, MenuItem|Product $model, float $price, string $station, Collection $chipsByCategory, array $pairs = []): array
    {
        $disk = Storage::disk(MenuPhotoProcessor::DISK);

        return [
            'key' => $prefix.$model->id,
            'type' => $prefix === 'm' ? 'menu_item' : 'product',
            'id' => $model->id,
            'name' => $model->name,
            'description' => $model->description,
            'price' => (int) round($price),
            'thumb' => $model->photo_thumb_path ? $disk->url($model->photo_thumb_path) : null,
            'large' => $model->photo_path ? $disk->url($model->photo_path) : null,
            'chips' => $chipsByCategory[$model->category_id] ?? [],
            'note' => $station === GuestStation::KITCHEN,
            'tab' => GuestStation::tabFor($station),
            'category' => $model->category?->name,
            // Phase 7C (D38) — owner-set, honest only.
            'badge' => array_key_exists((string) $model->guest_badge, \App\Support\GuestMenuOptions::BADGES) ? $model->guest_badge : null,
            'recommended' => (bool) $model->guest_recommended,
            'sort' => $model->guest_sort,
            'pairs' => $pairs[$prefix.$model->id] ?? [],
        ];
    }

    /** @return list<string> */
    private function computeUnavailable(): array
    {
        $soldOut = MenuItem::where('available_for_sale', false)->pluck('id')->map(fn ($id) => 'm'.$id);

        $products = Product::with('category')->where('is_active', true)->get();
        $stock = DB::table('inventory_items')
            ->whereIn('product_id', $products->pluck('id'))
            ->select('product_id', 'warehouse_id', DB::raw('SUM(quantity) as quantity'))
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->groupBy('product_id');

        $outOfStock = $products
            ->filter(function (Product $product) use ($stock) {
                $warehouseId = InventoryService::getWarehouseForProduct($product);
                $quantity = (float) ($stock[$product->id] ?? collect())->firstWhere('warehouse_id', $warehouseId)?->quantity;

                return $quantity <= 0;
            })
            ->map(fn (Product $product) => 'p'.$product->id);

        return $soldOut->merge($outOfStock)->values()->all();
    }

    private function productStock(Product $product): float
    {
        return (float) DB::table('inventory_items')
            ->where('product_id', $product->id)
            ->where('warehouse_id', InventoryService::getWarehouseForProduct($product))
            ->value('quantity');
    }

    /** @return list<array{key: string, qty: int}> */
    private function rankSales(string $businessDate): array
    {
        [$start, $end] = BusinessDay::boundsFor($businessDate);

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.created_at', '>=', $start)
            ->where('orders.created_at', '<', $end)
            ->whereNotIn('orders.status', ['cancelled', 'returned'])
            ->where('orders.is_return', false)
            ->select('order_items.item_type', 'order_items.menu_item_id', 'order_items.product_id')
            ->selectRaw('SUM(order_items.quantity) as qty')
            ->groupBy('order_items.item_type', 'order_items.menu_item_id', 'order_items.product_id')
            ->orderByDesc('qty')
            ->limit(40)
            ->get()
            ->map(fn ($row) => [
                'key' => $row->item_type === 'menu_item' ? 'm'.$row->menu_item_id : 'p'.$row->product_id,
                'qty' => (int) $row->qty,
            ])
            ->all();
    }
}
