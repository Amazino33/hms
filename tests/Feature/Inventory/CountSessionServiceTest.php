<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StaffDebt;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;

it('snapshots expected quantity at open and does not require it to record a count', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);

    $item = $session->items()->first();
    expect((float) $item->expected_quantity_at_open)->toEqual(24.0);
    expect($item->counted_quantity)->toBeNull();

    $item = $service->recordCount($item, ['Fridge' => 10]);
    $item = $service->recordCount($item, ['Floor' => 9]);

    expect((float) $item->counted_quantity)->toEqual(19.0);
    // Every item always gets exactly the warehouse's 3 fixed slots — Bar's
    // Fridge/Floor/Shelf — pre-seeded at session-open, not created ad hoc.
    expect($item->subCounts()->count())->toBe(3);
});

it('rejects a sub-location that is not one of the warehouse fixed 3 slots', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    expect(fn () => $service->recordCount($item, ['Basement' => 5]))->toThrow(Exception::class);
});

it('treats a blank/omitted sub-location quantity as zero, not an error', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    $item = $service->recordCount($item, ['Fridge' => 15, 'Floor' => '', 'Shelf' => null]);

    expect((float) $item->counted_quantity)->toEqual(15.0);
});

it('uses a warehouse own configured labels instead of the Fridge/Floor/Shelf default when set', function () {
    $bar = WareHouse::create([
        'name' => 'Bar',
        'type' => 'consumer',
        'sub_location_labels' => ['Cooler', 'Back Room', 'Display Case'],
    ]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    expect($item->subCounts()->pluck('sub_location')->sort()->values()->all())
        ->toBe(['Back Room', 'Cooler', 'Display Case']);

    expect(fn () => $service->recordCount($item, ['Fridge' => 5]))->toThrow(Exception::class);
    $service->recordCount($item, ['Cooler' => 5]);
    expect((float) $item->fresh()->counted_quantity)->toEqual(5.0);
});

it('blocks submission for review until both outgoing and incoming confirm a handover', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $service->recordCount($session->items()->first(), ['Fridge' => 19]);

    expect(fn () => $service->submitForReview($session))->toThrow(Exception::class);

    $service->confirmOutgoing($session, $outgoing->id);
    expect(fn () => $service->submitForReview($session->fresh()))->toThrow(Exception::class);

    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    expect($session->status)->toBe('pending_review');
});

it('rejects a confirmation from someone who is not the named custodian', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $stranger = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);

    expect(fn () => $service->confirmOutgoing($session, $stranger->id))->toThrow(Exception::class);
    expect(fn () => $service->confirmIncoming($session, $stranger->id))->toThrow(Exception::class);
});

it('computes variance against live stock, not the stale snapshot, when sales happen during counting', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    // Expected at open was 24.

    // Simulate 4 units being legitimately sold mid-count (a real sale via the
    // normal transaction pipeline while counting is still in progress).
    InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->decrement('quantity', 4);
    InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $bar->id, 'type' => 'sale',
        'quantity' => 4, 'user_id' => $outgoing->id,
    ]);
    // Live stock is now 20 — that is the correct "adjusted expected", not 24.

    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 18]); // physically counted 18

    $service->confirmOutgoing($session, $outgoing->id);
    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    $item = $item->fresh();
    expect((float) $item->adjusted_expected_quantity)->toEqual(20.0);
    expect((float) $item->variance)->toEqual(-2.0); // 18 counted vs 20 adjusted-expected
});

it('true-ups stock to the counted figure without charging anyone', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 20]);
    $service->confirmOutgoing($session, $outgoing->id);
    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    $item = $service->reviewItem($item->fresh(), $manager->id, 'true_up', 'Known small pour variance');

    expect((int) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(20);
    expect(InventoryTransaction::where('product_id', $product->id)->where('type', 'adjustment')->count())->toBe(1);
    expect(StaffDebt::count())->toBe(0);
});

it('charges the outgoing custodian at selling price for an accountability decision on a product shortfall', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 20]); // 4 short of 24
    $service->confirmOutgoing($session, $outgoing->id);
    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    $service->reviewItem($item->fresh(), $manager->id, 'accountability', 'Bartender could not account for the shortfall');

    expect((int) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(20);

    $debt = StaffDebt::first();
    expect($debt)->not->toBeNull();
    expect($debt->user_id)->toBe($outgoing->id); // outgoing custodian, not incoming
    expect((float) $debt->amount)->toEqual(2000.0); // 4 units * 500 selling price
    expect($debt->reason)->toBe('count_session_shortfall');
});

it('charges ingredient shortfalls at last purchase price, not selling price', function () {
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $ingredient = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE-CS', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    // Two purchases at different prices — the LAST one should be used.
    IngredientTransaction::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $kitchen->id, 'type' => 'purchase', 'quantity' => 5, 'cost_per_unit' => 4, 'user_id' => User::factory()->create()->id]);
    IngredientTransaction::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $kitchen->id, 'type' => 'purchase', 'quantity' => 5, 'cost_per_unit' => 7, 'user_id' => User::factory()->create()->id]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('kitchen_handover', $kitchen->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->where('ingredient_id', $ingredient->id)->first();
    $service->recordCount($item, ['Shelf A' => 8]); // 2kg short of 10

    $service->confirmOutgoing($session, $outgoing->id);
    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    $service->reviewItem($item->fresh(), $manager->id, 'accountability', 'Chef shortfall');

    $debt = StaffDebt::first();
    expect((float) $debt->amount)->toEqual(14.0); // 2kg * last purchase price (7), not 4
});

it('leaves stock untouched when a variance is ignored', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 23]);
    $service->confirmOutgoing($session, $outgoing->id);
    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    $service->reviewItem($item->fresh(), $manager->id, 'ignored', 'Within tolerance');

    expect((int) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(24);
    expect(InventoryTransaction::where('type', 'adjustment')->count())->toBe(0);
});

it('refuses to finalize a session while any variance is still undecided', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 20]);
    $service->confirmOutgoing($session, $outgoing->id);
    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    expect(fn () => $service->finalizeReview($session, $manager->id))->toThrow(Exception::class);

    $service->reviewItem($item->fresh(), $manager->id, 'true_up');
    $session = $service->finalizeReview($session->fresh(), $manager->id);

    expect($session->status)->toBe('reviewed');
    expect($session->reviewed_by)->toBe($manager->id);
});

it('supports a single-person main store stocktake without dual confirmation', function () {
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $mainStore->id, 'quantity' => 100]);

    $storekeeper = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('main_store_stocktake', $mainStore->id, $storekeeper->id);

    expect($session->outgoing_user_id)->toBeNull();
    expect($session->accountableUserId())->toBe($storekeeper->id);

    $item = $session->items()->first();
    $service->recordCount($item, ['Shelf A' => 100]);

    // No confirmations required for a stocktake.
    $session = $service->submitForReview($session->fresh());
    expect($session->status)->toBe('pending_review');

    $session = $service->finalizeReview($session->fresh(), $manager->id);
    expect($session->status)->toBe('reviewed');
});

it('backfills a zero-quantity Main Store row for a product only ever stocked at another warehouse, so a stocktake can count it', function () {
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    // This product has never had an InventoryItem row at Main Store —
    // e.g. it was first stocked via Quick Inventory Update at the Bar.
    $barOnlyProduct = Product::create(['name' => 'Bar-only Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $barOnlyProduct->id, 'warehouse_id' => $bar->id, 'quantity' => 12]);

    $storekeeper = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('main_store_stocktake', $mainStore->id, $storekeeper->id);

    $items = $session->items()->with('product')->get();
    expect($items->pluck('product_id'))->toContain($barOnlyProduct->id);

    $backfilled = $items->firstWhere('product_id', $barOnlyProduct->id);
    expect((int) $backfilled->expected_quantity_at_open)->toBe(0);

    // The Bar's own quantity must be untouched by the backfill.
    expect((int) InventoryItem::where('product_id', $barOnlyProduct->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(12);
});

it('does not backfill Main Store rows for an inactive product', function () {
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $discontinued = Product::create(['name' => 'Discontinued Drink', 'price' => 500, 'category_id' => $category->id, 'is_active' => false]);
    InventoryItem::create(['product_id' => $discontinued->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);

    $storekeeper = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('main_store_stocktake', $mainStore->id, $storekeeper->id);

    expect($session->items()->where('product_id', $discontinued->id)->exists())->toBeFalse();
    expect(InventoryItem::where('product_id', $discontinued->id)->where('warehouse_id', $mainStore->id)->exists())->toBeFalse();
});
