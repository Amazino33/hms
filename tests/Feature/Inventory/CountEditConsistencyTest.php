<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\Category;
use App\Models\CountSessionSubCount;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\PinAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Bug from live use: editing an already-entered count (via Previous, or a
 * summary-row jump) did not reflect on the declaration/review list. Audit
 * found the write (recordCount -> UPDATE) and read (declarationSummaryItems
 * -> fresh query) paths both correct in isolation — the real gap was that
 * the "Review & Declare" / "Finish Counting -> Seal" buttons opened with a
 * bare Alpine `show = true`, never calling into the save-confirmed gate at
 * all, so an edit made right before tapping straight into Declare (skipping
 * Next) never left the browser. These tests pin the underlying data
 * guarantees the fix depends on; the JS-level gate itself is pinned via a
 * markup/wiring assertion further down, since Pest can't run a real browser.
 */
it('reflects an edited value in the declaration summary, overwriting the first entry', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $item = $session->items()->where('product_id', $product->id)->first();

    $component = Livewire::actingAs($bartender)->test(CountSessionDetail::class, ['session_id' => $session->id]);

    // First entry.
    $component->call('recordCount', $item->id, ['Fridge' => '2', 'Floor' => '', 'Shelf' => '']);

    // The edit — same product, changed value, exactly like returning via
    // Previous or a summary-row tap and correcting the figure.
    $component->call('recordCount', $item->id, ['Fridge' => '9', 'Floor' => '', 'Shelf' => '']);

    expect((float) $item->fresh()->counted_quantity)->toBe(9.0);

    $summary = collect($component->instance()->declarationSummaryItems())->keyBy('id');
    expect($summary[$item->id]['values']['Fridge'])->toBe('9');
});

it('never creates a second sub-count row for the same item and sub-location across repeated edits', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $item = $session->items()->where('product_id', $product->id)->first();

    $labelCount = $item->subCounts()->count();

    $service->recordCount($item, ['Fridge' => 2], $bartender->id);
    $service->recordCount($item, ['Fridge' => 9], $bartender->id);
    $service->recordCount($item, ['Fridge' => 3], $bartender->id);

    expect($item->subCounts()->count())->toBe($labelCount);
    expect((float) $item->subCounts()->where('sub_location', 'Fridge')->value('quantity'))->toBe(3.0);
});

it('enforces a unique index on (count_session_item_id, sub_location) at the database level', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $item = $session->items()->where('product_id', $product->id)->first();
    $existingLocation = $item->subCounts()->first()->sub_location;

    expect(fn () => CountSessionSubCount::create([
        'count_session_item_id' => $item->id,
        'sub_location' => $existingLocation,
        'quantity' => 5,
    ]))->toThrow(QueryException::class);
});

it('collapses seeded duplicate draft rows to the most recently updated one before the unique index is reapplied', function () {
    Schema::table('count_session_sub_counts', function ($table) {
        $table->dropUnique('count_session_sub_counts_unique');
    });

    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $bartender = User::factory()->create();
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $item = $session->items()->where('product_id', $product->id)->first();
    $row = $item->subCounts()->where('sub_location', 'Fridge')->first();

    // Simulate a stray duplicate: an older row (stale quantity) and the row
    // that's actually most recently updated (the correct, final quantity).
    DB::table('count_session_sub_counts')->where('id', $row->id)->update([
        'quantity' => 2,
        'updated_at' => now()->subMinutes(5),
    ]);
    $duplicateId = DB::table('count_session_sub_counts')->insertGetId([
        'count_session_item_id' => $item->id,
        'sub_location' => 'Fridge',
        'quantity' => 9,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('count_session_sub_counts')
        ->where('count_session_item_id', $item->id)->where('sub_location', 'Fridge')->count())->toBe(2);

    $migration = require database_path('migrations/2026_07_12_161510_add_unique_index_to_count_session_sub_counts_table.php');
    $migration->up();

    $remaining = DB::table('count_session_sub_counts')
        ->where('count_session_item_id', $item->id)->where('sub_location', 'Fridge')->get();

    expect($remaining)->toHaveCount(1);
    expect((float) $remaining->first()->quantity)->toBe(9.0);
    expect($remaining->first()->id)->toBe($duplicateId);

    expect(fn () => CountSessionSubCount::create([
        'count_session_item_id' => $item->id,
        'sub_location' => 'Fridge',
        'quantity' => 1,
    ]))->toThrow(QueryException::class);
});

it('rejects a recordCount attempt after the session has been declared, keeping declared figures locked', function () {
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($outgoing, '5793');

    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->where('product_id', $product->id)->first();
    $service->recordCount($item, ['Fridge' => 5], $outgoing->id);

    $session = $service->declare($session, '5793', 'test-lock-declare');

    // A fresh instance, not the in-memory $item whose ->session relation is
    // still cached from before declare() — matching how a real request
    // would load the item anew each time.
    expect(fn () => $service->recordCount($item->fresh(), ['Fridge' => 99], $outgoing->id))
        ->toThrow(Exception::class, 'Counts can only be recorded while the session is still open.');

    expect((float) $item->fresh()->counted_quantity)->toBe(5.0);
});

it('shows the incoming custodian the final, edited pre-declaration figure on the review page', function () {
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($outgoing, '5793');

    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->where('product_id', $product->id)->first();

    $service->recordCount($item, ['Fridge' => 2], $outgoing->id);
    $service->recordCount($item, ['Fridge' => 9], $outgoing->id); // the edit
    $session = $service->declare($session, '5793', 'test-review-reflects-edit');

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $component = Livewire::actingAs($incoming)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $reviewItems = collect($component->instance()->safeReviewItems())->keyBy('id');

    expect($reviewItems[$item->id]['declaredValues']['Fridge'])->toBe('9');
});

/**
 * True browser-level "opening Declare/Seal flushes a pending edit first"
 * behavior isn't reachable from Pest (no real Alpine/DOM) — this pins the
 * markup wiring it depends on instead, the same way the earlier zero-nudge
 * row-tap wiring was pinned.
 */
it('wires the Declare and Seal buttons to flush a pending edit before opening', function () {
    $view = file_get_contents(resource_path('views/filament/pages/count-session-detail.blade.php'));

    expect($view)->toContain('function hmsRequestCountFlush()');
    expect($view)->toContain("window.addEventListener('request-count-flush'");
    expect($view)->toContain("window.dispatchEvent(new CustomEvent('count-flush-result'");
    expect($view)->toContain('async flushPendingEdit()');
    expect(substr_count($view, '@click="if (await hmsRequestCountFlush()) show = true"'))->toBe(2);
});
