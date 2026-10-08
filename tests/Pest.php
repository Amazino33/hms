<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Spatie's role() query scope calls findByName() for every role in the
 * list and throws RoleDoesNotExist if even one is missing — regardless of
 * whether any user actually holds it. PayrollCompilationService::
 * eligibleStaff() filters on all payroll-eligible roles at once, so every
 * payroll test needs every one of them to exist, not just the roles the
 * test's own users hold.
 */
function seedPayrollRoles(): void
{
    foreach (['admin', 'chef', 'manager', 'waiter', 'bartender', 'storekeeper', 'receptionist', 'porter', 'cashier'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]);
    }
}

/**
 * Seals a full bar handover end to end (open -> count -> declare -> bind ->
 * review -> seal) and returns every actor/model a discrepancy/snapshot test
 * needs. Shared across the handover-discrepancy test files rather than
 * duplicated per file.
 */
function sealedHandoverScenario(int $liveStockQuantity = 24, int $countedQuantity = 20): array
{
    static $call = 0;
    $call++;

    // Distinct, non-trivial 4-digit PINs per call — PinAuthService enforces
    // system-wide PIN uniqueness, so a hardcoded PIN would collide the
    // second time this helper runs within the same test.
    $outgoingPin = (string) (2461 + $call);
    $incomingPin = (string) (7358 + $call);

    // A fresh warehouse per call, not the hardcoded id=4 "Bar" — openSession()
    // snapshots EVERY InventoryItem row already at that warehouse, so reusing
    // one warehouse across repeated calls (e.g. two sessions in one test)
    // would pull in the earlier call's product too and leave it unreviewed,
    // blocking the seal on "every product must be reviewed."
    $bar = \App\Models\WareHouse::create(['name' => 'Bar ' . uniqid(), 'type' => 'consumer', 'is_active' => 1]);
    $category = \App\Models\Category::firstOrCreate(['name' => 'Drinks'], ['type' => 'drink']);
    $product = \App\Models\Product::create(['name' => 'Heineken ' . uniqid(), 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    \App\Models\InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => $liveStockQuantity]);

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = \App\Models\User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = \App\Models\User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new \App\Services\PinAuthService();
    $pinAuth->setPin($outgoing, $outgoingPin);
    $pinAuth->setPin($incoming, $incomingPin);

    $service = new \App\Services\CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => $countedQuantity], $outgoing->id);
    $session = $service->declare($session, $outgoingPin, 'seal-scenario-declare-' . uniqid());
    $session = $service->bindIncomingCustodian($session, $incomingPin, 'seal-scenario-bind-' . uniqid());
    $service->reviewProduct($item->fresh(), $incoming->id, 'accepted');
    $session = $service->sealAgreement($session, $outgoingPin, $incomingPin, 'seal-scenario-seal-' . uniqid());

    return compact('service', 'session', 'item', 'product', 'outgoing', 'incoming', 'bar', 'outgoingPin', 'incomingPin');
}


/**
 * Announcement fixtures, shared across the service and notice-board test
 * files rather than declared in one of them — Pest loads every test file
 * into the same global scope, so a second copy would be a fatal redeclare.
 *
 * Creates a DRAFT: nothing is live until AnnouncementService::publish()
 * runs, which is also what freezes the roster.
 */
function makeAnnouncement(array $attributes = []): \App\Models\Announcement
{
    return \App\Models\Announcement::create(array_merge([
        'title' => 'Staff meeting Friday',
        'body' => '<p>Everyone in the back office at 10am.</p>',
        'severity' => 'info',
        'must_acknowledge' => true,
        'show_on_kiosk' => true,
        'audience' => 'all',
    ], $attributes));
}

function announcementStaff(string $role, string $name = 'Staff'): \App\Models\User
{
    $user = \App\Models\User::factory()->create(['name' => $name]);
    $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]));

    return $user;
}

/**
 * Count breakdown fixtures: one bar with a product (₦1,000 sell / ₦600
 * cost, crates of 24), a store to transfer from, two bartenders with
 * PINs, two waiters, a storekeeper and a super-admin manager. The bar is
 * the only consumer warehouse, so InventoryService routes drink sales to
 * it exactly as in production.
 */
function cbSetup(float $barStock = 12): array
{
    static $pin = 1200;

    foreach (['bartender', 'waiter', 'storekeeper', 'super_admin', 'chef'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]);
    }

    $store = \App\Models\WareHouse::create(['name' => 'Main Store', 'type' => 'storage', 'is_active' => 1]);
    $bar = \App\Models\WareHouse::create(['name' => 'Bar', 'type' => 'consumer', 'is_active' => 1]);
    $category = \App\Models\Category::firstOrCreate(['name' => 'Drinks'], ['type' => 'drink']);
    $product = \App\Models\Product::create([
        'name' => 'Star Lager', 'price' => 1000, 'last_cost_price' => 600, 'category_id' => $category->id,
        'is_active' => true, 'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 24,
    ]);
    \App\Models\InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => $barStock]);
    \App\Models\InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $store->id, 'quantity' => 500]);

    $person = function (string $role, string $name) use (&$pin) {
        $user = \App\Models\User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        // PinAuthService rejects trivial and already-taken PINs; step past them.
        while (true) {
            $pin += 37;

            try {
                (new \App\Services\PinAuthService)->setPin($user, (string) $pin);
                break;
            } catch (\InvalidArgumentException $e) {
                continue;
            }
        }

        return [$user, (string) $pin];
    };

    [$bartenderA, $pinA] = $person('bartender', 'Ada Bartender');
    [$bartenderB, $pinB] = $person('bartender', 'Bola Bartender');
    [$waiterOne] = $person('waiter', 'Staff One');
    [$waiterTwo] = $person('waiter', 'Staff Two');
    [$storekeeper] = $person('storekeeper', 'Store Keeper');
    [$manager] = $person('super_admin', 'Manager Mo');

    return compact('store', 'bar', 'product', 'bartenderA', 'pinA', 'bartenderB', 'pinB', 'waiterOne', 'waiterTwo', 'storekeeper', 'manager');
}

/**
 * Seal a full dual-PIN bar handover, counting each product at the given
 * figure (product id => qty; anything missing counts as live stock).
 */
function cbSeal(\App\Models\WareHouse $bar, \App\Models\User $out, string $outPin, \App\Models\User $in, string $inPin, array $counts = [], string $type = 'bar_handover'): \App\Models\CountSession
{
    $service = new \App\Services\CountSessionService;
    $session = $service->openSession($type, $bar->id, $out->id, $out->id, $in->id);
    $slot = $bar->subLocationLabels()[0];

    foreach ($session->items as $item) {
        $id = $item->item_type === 'product' ? $item->product_id : $item->ingredient_id;
        $live = $item->item_type === 'product'
            ? (float) \App\Models\InventoryItem::where('product_id', $id)->where('warehouse_id', $bar->id)->value('quantity')
            : (float) \App\Models\IngredientInventoryItem::where('ingredient_id', $id)->where('warehouse_id', $bar->id)->value('quantity');
        $service->recordCount($item, [$slot => $counts[$id] ?? $live], $out->id);
    }

    $session = $service->declare($session, $outPin, 'cb-declare-'.uniqid());
    $session = $service->bindIncomingCustodian($session, $inPin, 'cb-bind-'.uniqid());

    foreach ($session->items()->get() as $item) {
        $service->reviewProduct($item, $in->id, 'accepted');
    }

    return $service->sealAgreement($session, $outPin, $inPin, 'cb-seal-'.uniqid());
}

/**
 * A bar sale through the real deduction path (an "order:{id}" sale row).
 */
function cbSale(\App\Models\Product $product, \App\Models\User $waiter, float $quantity, array $orderAttributes = []): \App\Models\Order
{
    $order = \App\Models\Order::create(array_merge([
        'order_number' => 'ORD-'.uniqid(),
        'status' => 'pending',
        'destination' => 'bar',
        'total_amount' => $product->price * $quantity,
        'user_id' => $waiter->id,
    ], $orderAttributes));

    \App\Models\OrderItem::create([
        'order_id' => $order->id,
        'item_type' => 'product',
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $product->price,
        'subtotal' => $product->price * $quantity,
    ]);

    \App\Services\InventoryService::deductInventoryForOrderItems($order->fresh('items'));

    return $order->fresh();
}

function cbTick(int $minutes = 1): void
{
    \Illuminate\Support\Carbon::setTestNow(now()->addMinutes($minutes));
}

/**
 * One full shift between two sealed counts, every movement type through
 * its real service:
 *
 *   B/F 12 (previous count) + 24 transferred + 1 returned
 *   - 3 sold (Staff One, marked ready after 47 min) - 6 sold (Staff Two)
 *   - 2 sold then voided by the manager (restocked, so not counted)
 *   - 1 damaged
 *   = 27 expected, counted 25 → short 2 (₦2,000 sell / ₦1,200 cost)
 */
function cbFullScenario(float $counted = 25): array
{
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(12);

    $c['previous'] = cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB']);
    cbTick();

    $transfers = new \App\Services\StockTransferService;
    $c['transfer'] = $transfers->createTransfer($c['store']->id, $c['bar']->id, $c['storekeeper']->id, [['product_id' => $c['product']->id, 'quantity' => 24]]);
    cbTick(5);
    $transfers->receiveTransfer($c['transfer'], $c['bartenderB']->id);
    cbTick();

    $c['saleSlow'] = cbSale($c['product'], $c['waiterOne'], 3);
    cbTick(47);
    $c['saleSlow']->update(['status' => 'ready']);

    $c['saleQuick'] = cbSale($c['product'], $c['waiterTwo'], 6);
    cbTick(5);
    $c['saleQuick']->update(['status' => 'ready']);

    $c['saleVoided'] = cbSale($c['product'], $c['waiterOne'], 2);
    cbTick();
    test()->actingAs($c['manager']);
    $c['saleVoided']->update(['status' => 'cancelled']);
    \Illuminate\Support\Facades\Auth::logout();

    $damages = new \App\Services\DamageReportService;
    $c['damage'] = $damages->report(['product_id' => $c['product']->id, 'quantity' => 1, 'note' => 'Bottle dropped'], $c['bar']->id, $c['bartenderB']->id);
    $damages->approve($c['damage'], $c['manager']->id);
    cbTick();

    $c['returnTicket'] = \App\Models\Order::create([
        'order_number' => 'RET-'.uniqid(), 'status' => 'pending', 'destination' => 'bar',
        'total_amount' => 1000, 'user_id' => $c['waiterTwo']->id, 'is_return' => true,
    ]);
    \App\Models\OrderItem::create([
        'order_id' => $c['returnTicket']->id, 'item_type' => 'product', 'product_id' => $c['product']->id,
        'product_name' => $c['product']->name, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'return_reason' => 'Guest changed mind',
    ]);
    (new \App\Services\ReturnConfirmationService)->confirm($c['returnTicket']->fresh('items'), $c['bartenderB']);
    cbTick();

    $c['session'] = cbSeal($c['bar'], $c['bartenderB'], $c['pinB'], $c['bartenderA'], $c['pinA'], [$c['product']->id => $counted]);
    $c['line'] = \App\Models\CountBreakdownLine::where('count_session_id', $c['session']->id)->first();

    return $c;
}
