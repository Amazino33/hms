<?php

use App\Models\Category;
use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\Ingredient;
use App\Models\IngredientTransaction;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTraceService;
use Carbon\CarbonImmutable;

/**
 * The direction map is the whole reason this service exists: transaction
 * quantities are stored as unsigned magnitudes, so a plain SUM is always
 * wrong. These lock down every branch of that map, including the ones the
 * schema genuinely cannot answer.
 */
beforeEach(function () {
    $this->service = new StockTraceService;
    $this->warehouse = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $this->drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
});

function txn(array $attributes = []): InventoryTransaction
{
    return new InventoryTransaction(array_merge([
        'type' => 'sale',
        'quantity' => 5,
        'reference' => 'order:1',
    ], $attributes));
}

it('treats purchase, return, opening_balance and transfer_reversal_in as adding stock', function () {
    foreach (['purchase', 'return', 'opening_balance', 'transfer_reversal_in'] as $type) {
        expect($this->service->signedQuantity(txn(['type' => $type, 'quantity' => 7])))
            ->toBe(7.0, "{$type} should be inbound");
    }
});

it('treats sale, usage and damage_write_off as removing stock', function () {
    foreach (['sale', 'usage', 'damage_write_off'] as $type) {
        expect($this->service->signedQuantity(txn(['type' => $type, 'quantity' => 7])))
            ->toBe(-7.0, "{$type} should be outbound");
    }
});

/**
 * Both legs of a transfer are written with the SAME type string. If
 * direction came from the type alone a transfer would net to double
 * instead of zero, quietly inventing stock in every trace that spans one.
 */
it('separates the two legs of a transfer by reference suffix, so a transfer nets to zero', function () {
    $out = $this->service->signedQuantity(txn(['type' => 'transfer', 'quantity' => 12, 'reference' => 'transfer:9:out']));
    $in = $this->service->signedQuantity(txn(['type' => 'transfer', 'quantity' => 12, 'reference' => 'transfer:9:in']));

    expect($out)->toBe(-12.0)
        ->and($in)->toBe(12.0)
        ->and($out + $in)->toBe(0.0);
});

it('recovers an adjustment direction by joining back to the signed quantity_change', function () {
    $reference = 'stock_adjustment:41:spoilage';

    expect($this->service->signedQuantity(txn(['type' => 'adjustment', 'quantity' => 6, 'reference' => $reference]), [41 => -6.0]))
        ->toBe(-6.0)
        ->and($this->service->signedQuantity(txn(['type' => 'adjustment', 'quantity' => 6, 'reference' => $reference]), [41 => 6.0]))
        ->toBe(6.0);
});

/**
 * bulk_stock_set and handover recount both store abs() with nothing signed
 * persisted anywhere to join back to. Guessing a direction here would
 * silently shift the "unexplained" figure the page exists to compute, so
 * the service must say "unknown" instead.
 */
it('reports direction as unknown rather than guessing when nothing signed was persisted', function () {
    $unknowable = [
        'bulk_stock_set:2026-09-01',
        'handover_discrepancy:17:recount',
    ];

    foreach ($unknowable as $reference) {
        expect($this->service->signedQuantity(txn(['type' => 'adjustment', 'quantity' => 4, 'reference' => $reference])))
            ->toBeNull("{$reference} direction is not recoverable and must not be guessed");
    }
});

it('classifies every reference format the app actually writes', function () {
    expect($this->service->referenceKind('order:5'))->toBe('sale')
        ->and($this->service->referenceKind('transfer:5:out'))->toBe('transfer')
        ->and($this->service->referenceKind('procurement:5'))->toBe('procurement')
        ->and($this->service->referenceKind('stock_adjustment:5:spoilage'))->toBe('stock_adjustment')
        ->and($this->service->referenceKind('count_session:5'))->toBe('count_true_up')
        ->and($this->service->referenceKind('handover_discrepancy:5:recount'))->toBe('recount_true_up')
        ->and($this->service->referenceKind('discrepancy:5:reversal'))->toBe('transfer_reversal')
        ->and($this->service->referenceKind('damage_report:5'))->toBe('damage')
        ->and($this->service->referenceKind('bulk_stock_set:2026-09-01'))->toBe('bulk_stock_set')
        ->and($this->service->referenceKind('booking:5'))->toBe('room_charge')
        ->and($this->service->referenceKind('opening_stock'))->toBe('opening_stock')
        ->and($this->service->referenceKind(null))->toBe('other');
});

it('puts billed and deducted quantities side by side and flags where they disagree', function () {
    $waiter = User::factory()->create(['name' => 'Chidi']);
    $product = Product::create([
        'name' => 'Amstel Malt', 'sku' => 'AM-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);

    $order = Order::create([
        'order_number' => 'ORD-1', 'user_id' => $waiter->id, 'status' => 'paid',
        'total_amount' => 4500, 'is_return' => false,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'item_type' => 'product',
        'product_name' => 'Amstel Malt', 'quantity' => 3, 'unit_price' => 1500, 'subtotal' => 4500,
    ]);

    // Only 2 of the 3 billed units actually left the warehouse.
    InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'type' => 'sale',
        'quantity' => 2, 'reference' => "order:{$order->id}", 'user_id' => $waiter->id,
    ]);

    $rows = $this->service->salesPivot(
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    expect($rows)->toHaveCount(1);
    expect($rows->first()['item_name'])->toBe('Amstel Malt')
        ->and($rows->first()['waiter_name'])->toBe('Chidi')
        ->and($rows->first()['billed_quantity'])->toBe(3.0)
        ->and($rows->first()['deducted_quantity'])->toBe(2.0)
        ->and($rows->first()['mismatch'])->toBe(-1.0);
});

/**
 * A dish never moves product stock, so a zero in the deducted column would
 * read as a discrepancy that isn't one. Menu items carry a recipe status
 * instead — and that status is exactly the diagnostic for whether the
 * kitchen ingredient deduction actually fired.
 */
it('reports a menu item whose recipe ingredients never moved as missing, not as a zero deduction', function () {
    $waiter = User::factory()->create(['name' => 'Ngozi']);
    $food = Category::create(['name' => 'Mains', 'type' => 'food']);
    $rice = Ingredient::create([
        'name' => 'Rice', 'sku' => 'ING-1', 'unit_name' => 'kg',
        'quantity' => 50, 'cost_per_unit' => 800, 'category' => 'Grains',
    ]);
    $menuItem = MenuItem::create([
        'name' => 'Jollof Rice', 'sku' => 'JOL-1', 'category_id' => $food->id,
        'sale_price' => 2500, 'available_for_sale' => true,
    ]);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 0.3]);

    $order = Order::create([
        'order_number' => 'ORD-2', 'user_id' => $waiter->id, 'status' => 'paid',
        'total_amount' => 2500, 'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_type' => 'menu_item',
        'product_name' => 'Jollof Rice', 'quantity' => 1, 'unit_price' => 2500, 'subtotal' => 2500,
    ]);

    $row = $this->service->salesPivot(
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    )->first();

    expect($row['item_type'])->toBe('menu_item')
        ->and($row['deducted_quantity'])->toBeNull()
        ->and($row['recipe_status'])->toBe('missing');

    IngredientTransaction::create([
        'ingredient_id' => $rice->id, 'warehouse_id' => $this->warehouse->id, 'type' => 'usage',
        'quantity' => 0.3, 'reference' => "order:{$order->id}", 'user_id' => $waiter->id,
    ]);

    expect($this->service->salesPivot(
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    )->first()['recipe_status'])->toBe('recorded');
});

it('marks a menu item with no recipe as untracked by design rather than missing', function () {
    $waiter = User::factory()->create();
    $food = Category::create(['name' => 'Service', 'type' => 'service']);
    $menuItem = MenuItem::create([
        'name' => 'Corkage', 'sku' => 'SRV-1', 'category_id' => $food->id,
        'sale_price' => 1000, 'available_for_sale' => true,
    ]);

    $order = Order::create([
        'order_number' => 'ORD-3', 'user_id' => $waiter->id, 'status' => 'paid',
        'total_amount' => 1000, 'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'item_type' => 'menu_item',
        'product_name' => 'Corkage', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000,
    ]);

    expect($this->service->salesPivot(
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    )->first()['recipe_status'])->toBe('no_recipe');
});

/**
 * The deduction is written once per product per order, while a customer
 * can be billed on two separate lines of the same drink. Summing the
 * deduction per line instead of per order would double it and invent a
 * mismatch that does not exist.
 */
it('does not double-count the deduction when one order bills the same product on two lines', function () {
    $waiter = User::factory()->create();
    $product = Product::create([
        'name' => 'Star Lager', 'sku' => 'SL-1', 'category_id' => $this->drinks->id,
        'price' => 1200, 'cost_price' => 700,
    ]);
    $order = Order::create([
        'order_number' => 'ORD-4', 'user_id' => $waiter->id, 'status' => 'paid',
        'total_amount' => 4800, 'is_return' => false,
    ]);

    foreach ([2, 2] as $quantity) {
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'item_type' => 'product',
            'product_name' => 'Star Lager', 'quantity' => $quantity, 'unit_price' => 1200, 'subtotal' => 2400,
        ]);
        InventoryTransaction::create([
            'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'type' => 'sale',
            'quantity' => $quantity, 'reference' => "order:{$order->id}", 'user_id' => $waiter->id,
        ]);
    }

    $row = $this->service->salesPivot(
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    )->first();

    expect($row['billed_quantity'])->toBe(4.0)
        ->and($row['deducted_quantity'])->toBe(4.0)
        ->and($row['mismatch'])->toBe(0.0);
});

/**
 * The count true-up is the row that CLOSES a variance — it sets live stock
 * to the counted figure. Counting it as a movement that explains the same
 * variance nets every trace to a tidy zero and makes the page useless.
 */
it('excludes a session own count true-up from the movements explaining its variance', function () {
    $user = User::factory()->create();
    $product = Product::create([
        'name' => 'Heineken', 'sku' => 'HK-1', 'category_id' => $this->drinks->id,
        'price' => 2000, 'cost_price' => 1200,
    ]);

    $previous = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $this->warehouse->id, 'status' => 'reviewed',
        'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now()->subDays(3),
        'reviewed_at' => CarbonImmutable::now()->subDays(3),
    ]);
    CountSessionItem::create([
        'count_session_id' => $previous->id, 'item_type' => 'product', 'product_id' => $product->id,
        'expected_quantity_at_open' => 100, 'counted_quantity' => 100,
    ]);

    $session = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $this->warehouse->id, 'status' => 'reviewed',
        'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now()->subDay(),
        'submitted_for_review_at' => CarbonImmutable::now(), 'reviewed_at' => CarbonImmutable::now(),
    ]);
    $item = CountSessionItem::create([
        'count_session_id' => $session->id, 'item_type' => 'product', 'product_id' => $product->id,
        'expected_quantity_at_open' => 90, 'counted_quantity' => 88, 'variance' => -2,
    ]);

    // 10 sold, then the session trued stock up by 2 to match the count.
    InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'type' => 'sale',
        'quantity' => 10, 'reference' => 'order:99', 'user_id' => $user->id,
        'created_at' => CarbonImmutable::now()->subHours(5),
    ]);
    InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'type' => 'adjustment',
        'quantity' => 2, 'reference' => "count_session:{$session->id}", 'user_id' => $user->id,
        'created_at' => CarbonImmutable::now()->subMinutes(1),
    ]);

    $ladder = $this->service->ladder($session, $item);

    expect($ladder['opening'])->toBe(100.0)
        ->and($ladder['net'])->toBe(-10.0)
        ->and($ladder['expected'])->toBe(90.0)
        ->and($ladder['counted'])->toBe(88.0)
        ->and($ladder['unexplained'])->toBe(-2.0)
        ->and($ladder['movements']->pluck('kind')->all())->not->toContain('count_true_up');
});

it('anchors the opening balance on the previous reviewed count, and says so when there is none', function () {
    $user = User::factory()->create();
    $product = Product::create([
        'name' => 'Trophy', 'sku' => 'TR-1', 'category_id' => $this->drinks->id,
        'price' => 1000, 'cost_price' => 600,
    ]);

    $session = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $this->warehouse->id, 'status' => 'reviewed',
        'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now()->subDay(),
        'submitted_for_review_at' => CarbonImmutable::now(), 'reviewed_at' => CarbonImmutable::now(),
    ]);
    $item = CountSessionItem::create([
        'count_session_id' => $session->id, 'item_type' => 'product', 'product_id' => $product->id,
        'expected_quantity_at_open' => 10, 'counted_quantity' => 9, 'variance' => -1,
    ]);

    $ladder = $this->service->ladder($session, $item);

    expect($ladder['opening'])->toBeNull()
        ->and($ladder['expected'])->toBeNull()
        ->and($ladder['unexplained'])->toBeNull()
        ->and($ladder['opening_source'])->toContain('opening balance unknown');
});

it('surfaces direction-unknown movements separately instead of folding them into the net', function () {
    $user = User::factory()->create();
    $product = Product::create([
        'name' => 'Legend', 'sku' => 'LG-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);

    $previous = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $this->warehouse->id, 'status' => 'reviewed',
        'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now()->subDays(3),
        'reviewed_at' => CarbonImmutable::now()->subDays(3),
    ]);
    CountSessionItem::create([
        'count_session_id' => $previous->id, 'item_type' => 'product', 'product_id' => $product->id,
        'expected_quantity_at_open' => 40, 'counted_quantity' => 40,
    ]);

    $session = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $this->warehouse->id, 'status' => 'reviewed',
        'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now()->subDay(),
        'submitted_for_review_at' => CarbonImmutable::now(), 'reviewed_at' => CarbonImmutable::now(),
    ]);
    $item = CountSessionItem::create([
        'count_session_id' => $session->id, 'item_type' => 'product', 'product_id' => $product->id,
        'expected_quantity_at_open' => 35, 'counted_quantity' => 35, 'variance' => 0,
    ]);

    InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'type' => 'adjustment',
        'quantity' => 5, 'reference' => 'bulk_stock_set:2026-09-01', 'user_id' => $user->id,
        'created_at' => CarbonImmutable::now()->subHours(4),
    ]);

    $ladder = $this->service->ladder($session, $item);

    expect($ladder['net'])->toBe(0.0)
        ->and($ladder['unknown_direction'])->toHaveCount(1)
        ->and($ladder['unknown_direction']->first()['kind'])->toBe('bulk_stock_set');
});

it('resolves an adjustment direction inside the ladder from the stock adjustment record', function () {
    $user = User::factory()->create();
    $product = Product::create([
        'name' => 'Smirnoff', 'sku' => 'SM-1', 'category_id' => $this->drinks->id,
        'price' => 2500, 'cost_price' => 1500,
    ]);

    $previous = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $this->warehouse->id, 'status' => 'reviewed',
        'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now()->subDays(3),
        'reviewed_at' => CarbonImmutable::now()->subDays(3),
    ]);
    CountSessionItem::create([
        'count_session_id' => $previous->id, 'item_type' => 'product', 'product_id' => $product->id,
        'expected_quantity_at_open' => 20, 'counted_quantity' => 20,
    ]);

    $session = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $this->warehouse->id, 'status' => 'reviewed',
        'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now()->subDay(),
        'submitted_for_review_at' => CarbonImmutable::now(), 'reviewed_at' => CarbonImmutable::now(),
    ]);
    $item = CountSessionItem::create([
        'count_session_id' => $session->id, 'item_type' => 'product', 'product_id' => $product->id,
        'expected_quantity_at_open' => 17, 'counted_quantity' => 17, 'variance' => 0,
    ]);

    $adjustment = StockAdjustment::create([
        'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'item_type' => 'product',
        'quantity_change' => -3, 'reason' => 'spillage_wastage', 'status' => 'approved',
        'requested_by' => $user->id,
    ]);

    InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'type' => 'adjustment',
        'quantity' => 3, 'reference' => "stock_adjustment:{$adjustment->id}:spillage_wastage", 'user_id' => $user->id,
        'created_at' => CarbonImmutable::now()->subHours(4),
    ]);

    $ladder = $this->service->ladder($session, $item);

    expect($ladder['net'])->toBe(-3.0)
        ->and($ladder['expected'])->toBe(17.0)
        ->and($ladder['unexplained'])->toBe(0.0)
        ->and($ladder['unknown_direction'])->toHaveCount(0);
});

it('excludes cancelled and returned orders from the pivot', function () {
    $waiter = User::factory()->create();
    $product = Product::create([
        'name' => 'Guinness', 'sku' => 'GN-1', 'category_id' => $this->drinks->id,
        'price' => 1800, 'cost_price' => 1000,
    ]);

    foreach ([['cancelled', false], ['paid', true]] as [$status, $isReturn]) {
        $order = Order::create([
            'order_number' => 'ORD-'.$status.($isReturn ? '-r' : ''), 'user_id' => $waiter->id,
            'status' => $status, 'total_amount' => 1800, 'is_return' => $isReturn,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'item_type' => 'product',
            'product_name' => 'Guinness', 'quantity' => 1, 'unit_price' => 1800, 'subtotal' => 1800,
        ]);
    }

    expect($this->service->salesPivot(
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    ))->toHaveCount(0);
});
