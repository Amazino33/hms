<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The skip-zero filter (count only products with stock, plus a catch step)
 * was reverted to a toggle defaulting to 'all': during this testing/
 * stabilization phase, products sometimes reach the bar from the store
 * without being recorded, so a system-zero product must still be counted —
 * counting it above zero is what surfaces that unrecorded movement.
 */
it('defaults to counting every bar-stocked product regardless of quantity (scope=all)', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();

    $withStock1 = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $withStock2 = Product::create(['name' => 'Wine', 'price' => 800, 'category_id' => $category->id, 'is_active' => true]);
    $zeroStock = Product::create(['name' => 'Rum', 'price' => 1200, 'category_id' => $category->id, 'is_active' => true]);

    InventoryItem::create(['product_id' => $withStock1->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);
    InventoryItem::create(['product_id' => $withStock2->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);
    InventoryItem::create(['product_id' => $zeroStock->id, 'warehouse_id' => $bar->id, 'quantity' => 0]);

    $service = new CountSessionService();
    expect($service->handoverCountScope())->toBe('all');

    $session = $service->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    expect($session->count_scope)->toBe('all');
    expect($session->items()->count())->toBe(3);
    expect($session->items()->where('product_id', $zeroStock->id)->exists())->toBeTrue();
});

it('filters out zero-stock bar products when the admin switches scope to in_stock_only', function () {
    Company::updateOrCreate(['id' => 1], ['name' => 'Test Co', 'handover_count_scope' => 'in_stock_only']);

    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();

    $withStock1 = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $withStock2 = Product::create(['name' => 'Wine', 'price' => 800, 'category_id' => $category->id, 'is_active' => true]);
    $zeroStock = Product::create(['name' => 'Rum', 'price' => 1200, 'category_id' => $category->id, 'is_active' => true]);

    InventoryItem::create(['product_id' => $withStock1->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);
    InventoryItem::create(['product_id' => $withStock2->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);
    InventoryItem::create(['product_id' => $zeroStock->id, 'warehouse_id' => $bar->id, 'quantity' => 0]);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    expect($session->count_scope)->toBe('in_stock_only');
    expect($session->items()->count())->toBe(2);
    expect($session->items()->where('product_id', $zeroStock->id)->exists())->toBeFalse();
    expect($session->items()->where('product_id', $withStock1->id)->exists())->toBeTrue();
    expect($session->items()->where('product_id', $withStock2->id)->exists())->toBeTrue();
});

it('never filters main_store_stocktake regardless of the handover scope setting', function () {
    Company::updateOrCreate(['id' => 1], ['name' => 'Test Co', 'handover_count_scope' => 'in_stock_only']);

    $store = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $manager = User::factory()->create();

    $withStock = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $zeroStock = Product::create(['name' => 'Rum', 'price' => 1200, 'category_id' => $category->id, 'is_active' => true]);

    InventoryItem::create(['product_id' => $withStock->id, 'warehouse_id' => $store->id, 'quantity' => 10]);
    InventoryItem::create(['product_id' => $zeroStock->id, 'warehouse_id' => $store->id, 'quantity' => 0]);

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $manager->id);

    expect($session->items()->count())->toBe(2);
});

it('never changes an already-open session when the admin flips the scope setting afterward', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();

    $withStock = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $zeroStock = Product::create(['name' => 'Rum', 'price' => 1200, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $withStock->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);
    InventoryItem::create(['product_id' => $zeroStock->id, 'warehouse_id' => $bar->id, 'quantity' => 0]);

    // Opened while scope defaults to 'all' — both products land on the list.
    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    expect($session->items()->count())->toBe(2);

    // Admin changes the setting mid-session.
    Company::updateOrCreate(['id' => 1], ['name' => 'Test Co', 'handover_count_scope' => 'in_stock_only']);

    expect($session->fresh()->items()->count())->toBe(2);
    expect($session->fresh()->count_scope)->toBe('all');
});

it('adds a catch-step item with zero expected quantity that seals as a plain overage when scope is in_stock_only', function () {
    Company::updateOrCreate(['id' => 1], ['name' => 'Test Co', 'handover_count_scope' => 'in_stock_only']);

    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $withStock = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $withStock->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    // Genuinely zero stock in the ledger — excluded from the frozen list,
    // but physically found during the count (e.g. an unrecorded restock).
    $foundProduct = Product::create(['name' => 'Tequila', 'price' => 2000, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $foundProduct->id, 'warehouse_id' => $bar->id, 'quantity' => 0]);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    expect($session->items()->count())->toBe(1); // only Beer, Tequila skipped

    $item = (new CountSessionService())->addCatchItem($session, 'product', $foundProduct->id, $bartender->id);

    expect($item->expected_quantity_at_open)->toEqual(0);
    expect($session->items()->count())->toBe(2);

    (new CountSessionService())->recordCount($item, ['Fridge' => 3], $bartender->id);

    expect((float) $item->fresh()->counted_quantity)->toBe(3.0);
});

it('refuses to add the same catch item twice', function () {
    Company::updateOrCreate(['id' => 1], ['name' => 'Test Co', 'handover_count_scope' => 'in_stock_only']);

    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $extra = Product::create(['name' => 'Rum', 'price' => 1200, 'category_id' => $category->id, 'is_active' => true]);

    (new CountSessionService())->addCatchItem($session, 'product', $extra->id, $bartender->id);

    expect(fn () => (new CountSessionService())->addCatchItem($session, 'product', $extra->id, $bartender->id))
        ->toThrow(Exception::class, 'This item is already in the count.');
});

it('hides the catch step when a session was opened in the default all scope', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $notOnSession = Product::create(['name' => 'Vodka', 'price' => 900, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    expect($session->count_scope)->toBe('all');

    $component = Livewire::actingAs($bartender)->test(CountSessionDetail::class, ['session_id' => $session->id]);

    expect($component->instance()->catchStepEnabled())->toBeFalse();
    expect($component->instance()->catchCandidates())->toBe([]);
    expect($component->instance()->addCatchItem('product', $notOnSession->id))->toBeNull();
    expect($session->fresh()->items()->count())->toBe(1);
});

it('flags an all-zero row on the declaration summary and leaves a genuinely counted row unflagged', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $counted = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $missed = Product::create(['name' => 'Wine', 'price' => 800, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $counted->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);
    InventoryItem::create(['product_id' => $missed->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $countedItem = $session->items()->where('product_id', $counted->id)->first();
    $service->recordCount($countedItem, ['Fridge' => 8], $bartender->id);

    $component = Livewire::actingAs($bartender)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $summary = collect($component->instance()->declarationSummaryItems())->keyBy('name');

    expect($summary['Beer']['isAllZero'])->toBeFalse();
    expect($summary['Wine']['isAllZero'])->toBeTrue();
});

/**
 * True browser-level "tap jumps to the product page" behavior isn't
 * reachable from Pest — this pins the markup wiring it depends on instead:
 * each declaration row is a button keyed to its item id and dispatches the
 * same window event count-session-detail.blade.php's counting-flow x-data
 * listens for in init().
 */
it('wires each declaration row to dispatch a jump-to-count-product event keyed to its item', function () {
    $view = file_get_contents(resource_path('views/filament/pages/count-session-detail.blade.php'));

    expect($view)->toContain('wire:key="declare-row-{{ $summaryItem[\'id\'] }}"');
    expect($view)->toContain("\$dispatch('jump-to-count-product', {{ \$summaryItem['id'] }})");
    expect($view)->toContain("window.addEventListener('jump-to-count-product'");
});

/**
 * Pest can't execute CSS media queries, so this pins the source instead:
 * every kiosk animation class must have its motion neutralized under
 * prefers-reduced-motion, per the fix prompt's "disabled under
 * prefers-reduced-motion" requirement.
 */
it('gates every kiosk animation class under prefers-reduced-motion in the compiled theme', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)->toContain('@media (prefers-reduced-motion: reduce)');

    foreach (['kiosk-primary-pulse', 'kiosk-attention-pulse', 'kiosk-field-active', 'kiosk-tap'] as $class) {
        expect($css)->toContain(".{$class}");

        $reducedMotionBlockStart = strpos($css, '@media (prefers-reduced-motion: reduce)');
        expect($reducedMotionBlockStart)->not->toBeFalse();

        // At least one of the reduced-motion blocks must mention this class.
        $mentionedInAReducedBlock = false;
        $offset = 0;
        while (($pos = strpos($css, '@media (prefers-reduced-motion: reduce)', $offset)) !== false) {
            $blockEnd = strpos($css, '}', strpos($css, '{', $pos) + 1);
            $block = substr($css, $pos, ($blockEnd !== false ? $blockEnd - $pos : 200));
            if (str_contains($block, $class)) {
                $mentionedInAReducedBlock = true;
                break;
            }
            $offset = $pos + 1;
        }

        expect($mentionedInAReducedBlock)->toBeTrue("Expected .{$class} to be neutralized under prefers-reduced-motion.");
    }
});
