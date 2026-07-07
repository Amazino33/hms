<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\FridgeStockEstimateService;

function seedFridgeProduct(WareHouse $bar, ?float $fridgePar = 12): Product
{
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Tiger Beer',
        'price' => 500,
        'category_id' => $category->id,
        'is_active' => true,
        'fridge_par' => $fridgePar,
    ]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    return $product;
}

function reviewedBarHandoverWithFridgeCount(WareHouse $bar, Product $product, float $fridgeQuantity): void
{
    reviewedBarHandoverWithFridgeCounts($bar, [$product->id => $fridgeQuantity]);
}

/**
 * A real handover count covers every product in the warehouse in ONE
 * session — recording figures for only a subset here (matching real usage)
 * avoids each call's session clobbering other products' baselines with an
 * unrecorded (zero) Fridge figure from a later, unrelated count.
 */
function reviewedBarHandoverWithFridgeCounts(WareHouse $bar, array $fridgeQuantitiesByProductId): void
{
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);

    foreach ($fridgeQuantitiesByProductId as $productId => $fridgeQuantity) {
        $item = $session->items()->where('product_id', $productId)->first();
        $service->recordCount($item, ['Fridge' => $fridgeQuantity]);
    }

    $service->confirmOutgoing($session, $outgoing->id);
    $service->confirmIncoming($session, $incoming->id);
    $session = $service->submitForReview($session->fresh());

    foreach ($session->items as $sessionItem) {
        if (abs((float) $sessionItem->variance) > 0.0001) {
            $service->reviewItem($sessionItem, $manager->id, 'ignored');
        }
    }

    $service->finalizeReview($session->fresh(), $manager->id);
}

it('returns null when there is no baseline yet', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $product = seedFridgeProduct($bar);

    expect((new FridgeStockEstimateService())->estimate($product, $bar))->toBeNull();
});

it('estimates cold stock from the last reviewed count minus sales since', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $product = seedFridgeProduct($bar);

    reviewedBarHandoverWithFridgeCount($bar, $product, 20);

    InventoryTransaction::create([
        'product_id' => $product->id,
        'warehouse_id' => $bar->id,
        'type' => 'sale',
        'quantity' => 6,
        'user_id' => User::factory()->create()->id,
    ]);

    expect((new FridgeStockEstimateService())->estimate($product, $bar))->toEqual(14.0);
});

it('never returns a negative estimate even if sales exceed the baseline', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $product = seedFridgeProduct($bar);

    reviewedBarHandoverWithFridgeCount($bar, $product, 5);

    InventoryTransaction::create([
        'product_id' => $product->id,
        'warehouse_id' => $bar->id,
        'type' => 'sale',
        'quantity' => 20,
        'user_id' => User::factory()->create()->id,
    ]);

    expect((new FridgeStockEstimateService())->estimate($product, $bar))->toEqual(0.0);
});

it('resets the estimate to par via the one-tap restocked action, without creating an InventoryTransaction', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $product = seedFridgeProduct($bar, fridgePar: 12);

    reviewedBarHandoverWithFridgeCount($bar, $product, 2); // now below par

    $manager = User::factory()->create();
    $transactionsBefore = InventoryTransaction::count();

    (new FridgeStockEstimateService())->markRestockedToPar($product, $bar, $manager->id);

    expect(InventoryTransaction::count())->toBe($transactionsBefore);
    expect((new FridgeStockEstimateService())->estimate($product, $bar))->toEqual(12.0);
});

it('refuses to mark restocked for a product with no fridge par set', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $product = seedFridgeProduct($bar, fridgePar: null);

    $manager = User::factory()->create();

    expect(fn () => (new FridgeStockEstimateService())->markRestockedToPar($product, $bar, $manager->id))
        ->toThrow(Exception::class);
});

it('lists only products with a par set whose known estimate is below it', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $belowPar = Product::create(['name' => 'Below Par Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true, 'fridge_par' => 12]);
    InventoryItem::create(['product_id' => $belowPar->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    $abovePar = Product::create(['name' => 'Above Par Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true, 'fridge_par' => 12]);
    InventoryItem::create(['product_id' => $abovePar->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    $noParSet = Product::create(['name' => 'No Par Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $noParSet->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    // One real handover count covering everything counted so far.
    reviewedBarHandoverWithFridgeCounts($bar, [
        $belowPar->id => 3,
        $abovePar->id => 20,
        $noParSet->id => 1,
    ]);

    // Added after the count closed — genuinely never counted, no baseline.
    $neverCounted = Product::create(['name' => 'Never Counted Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true, 'fridge_par' => 12]);
    InventoryItem::create(['product_id' => $neverCounted->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    $list = (new FridgeStockEstimateService())->belowParProducts($bar);

    expect($list->pluck('product.name')->all())->toBe(['Below Par Beer']);
});
