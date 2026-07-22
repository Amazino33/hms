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
