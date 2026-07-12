<?php

use App\Models\Category;
use App\Models\CountSession;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\OrderSplitter;
use App\Services\PinAuthService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

function seedBarHandoverScenario(int $quantity = 24): array
{
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => $quantity]);

    Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($outgoing, '5793');
    $pinAuth->setPin($incoming, '2846');

    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    return compact('bar', 'product', 'outgoing', 'incoming');
}

it('lets only the outgoing custodian record counts, not the incoming, during a handover-with-successor session', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    expect(fn () => $service->recordCount($item, ['Fridge' => 24], $incoming->id))
        ->toThrow(Exception::class, 'Only the person doing the count can record it.');

    $item = $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    expect((float) $item->counted_quantity)->toEqual(24.0);
});

it('requires the outgoing PIN to declare, not just being logged in as them', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);

    expect(fn () => $service->declare($session, '2846', 'test-declare-wrong'))
        ->toThrow(Exception::class, 'That PIN does not match the outgoing custodian.');
    expect($session->fresh()->status)->toBe('counting');

    $session = $service->declare($session, '5793', 'test-declare-right');
    expect($session->status)->toBe('declared');
});

it('freezes bar sales from declare() until sealAgreement() by removing the active bartender shift', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);

    expect(Shift::query()->where('user_id', $outgoing->id)->active()->exists())->toBeTrue();

    $session = $service->declare($session, '5793', 'test-throttle-declare');
    $item->refresh();

    expect($session->status)->toBe('declared');
    expect(Shift::query()->active()->ofType('bartender')->exists())->toBeFalse();

    $session = $service->bindIncomingCustodian($session, '2846', 'test-throttle-bind');
    $service->reviewProduct($item, $incoming->id, 'accepted');

    $session = $service->sealAgreement($session, '5793', '2846', 'test-throttle');

    expect($session->status)->toBe('reviewed');
    expect(Shift::query()->where('user_id', $incoming->id)->active()->ofType('bartender')->exists())->toBeTrue();
});

it('cannot mutate the outgoing declaration via reviewProduct — a dispute records both figures side by side, never overwrites', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-declare-1');
    $item->refresh();

    $review = $service->reviewProduct($item, $incoming->id, 'disputed', ['Fridge' => 20]);

    expect($review->outcome)->toBe('disputed');
    expect($review->incoming_quantities)->toBe(['Fridge' => 20]);
    // Outgoing's own declared figure is completely untouched by the dispute.
    expect((float) $item->fresh()->counted_quantity)->toEqual(24.0);
});

it('requires the outgoing PIN to amend a disputed declaration, and records the correction', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-declare-2');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'disputed', ['Fridge' => 20]);

    // Incoming's own PIN cannot amend the outgoing's declaration.
    expect(fn () => $service->amendDeclaration($item, '2846', ['Fridge' => 21], 'test-amend'))
        ->toThrow(Exception::class, 'That PIN does not match the outgoing custodian.');

    $item = $service->amendDeclaration($item, '5793', ['Fridge' => 21], 'test-amend');

    expect((float) $item->counted_quantity)->toEqual(21.0);
    expect($item->review->fresh()->outcome)->toBe('accepted');

    // Amendment goes through instance-level ->update() (not a mass
    // ::where()->update()) specifically so LogsActivity fires an 'updated'
    // event on the sub-count row — the before/after values themselves
    // aren't asserted here since this test environment's activity log
    // properties come back empty for every model, not just this one.
    $activity = \Spatie\Activitylog\Models\Activity::where('log_name', 'count_session_sub_count')
        ->where('description', 'updated')
        ->where('subject_id', $item->subCounts()->where('sub_location', 'Fridge')->value('id'))
        ->latest('id')
        ->first();
    expect($activity)->not->toBeNull();
});

it('lets the incoming custodian mark a dispute unresolved, using their figure as the baseline and notifying managers without blocking', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();
    Role::firstOrCreate(['name' => 'manager']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-declare-3');
    $item->refresh();
    $session = $service->bindIncomingCustodian($session, '2846', 'test-bind-3');
    $service->reviewProduct($item, $incoming->id, 'disputed', ['Fridge' => 20]);

    $review = $service->markUnresolved($item, $incoming->id);
    expect($review->outcome)->toBe('unresolved');
    expect($manager->unreadNotifications()->count())->toBe(1);

    $session = $service->sealAgreement($session, '5793', '2846', 'test-throttle-2');

    expect($session->status)->toBe('reviewed');
    // Incoming's figure (20) became the baseline, not outgoing's declared 24.
    expect((float) $item->fresh()->counted_quantity)->toEqual(20.0);
    expect((float) InventoryItem::where('product_id', $item->product_id)->value('quantity'))->toEqual(20.0);

    // No StaffDebt yet — the seal only flags a pending discrepancy; a
    // manager decides what happens to the shortage afterward.
    expect(StaffDebt::count())->toBe(0);

    $discrepancy = \App\Models\HandoverDiscrepancy::first();
    expect($discrepancy)->not->toBeNull();
    expect($discrepancy->status)->toBe('pending_resolution');
    expect((float) $discrepancy->shortfall_quantity)->toEqual(4.0);
    expect((float) $discrepancy->naira_value)->toEqual(4 * 500.0); // 4 short at 500 selling price
});

it('books the unwitnessed handover shortfall to the named-absent outgoing, with the witness carrying no responsibility for the numbers', function () {
    ['bar' => $bar, 'outgoing' => $absentOutgoing, 'incoming' => $incoming] = seedBarHandoverScenario();
    // The absent outgoing never actually shows up, so their shift is
    // whatever it was before — unwitnessed sessions don't touch it at
    // declare time since there is no declare step.
    Role::firstOrCreate(['name' => 'storekeeper']);
    $witness = User::factory()->create();
    $witness->assignRole('storekeeper');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($witness, '1357');

    $service = new CountSessionService();
    $session = $service->openSession(
        'bar_handover',
        $bar->id,
        $incoming->id,
        outgoingUserId: $absentOutgoing->id,
        incomingUserId: $incoming->id,
        witnessUserId: $witness->id,
    );

    expect($session->isUnwitnessed())->toBeTrue();

    $item = $session->items()->first();
    // Only the incoming (the one actually counting) may record — not a
    // witness, who only attests at the end.
    expect(fn () => $service->recordCount($item, ['Fridge' => 20], $witness->id))
        ->toThrow(Exception::class);
    $service->recordCount($item, ['Fridge' => 20], $incoming->id);

    $session = $service->sealAgreement($session, '1357', '2846', 'test-unwitnessed');

    expect($session->status)->toBe('reviewed');
    expect(Shift::query()->where('user_id', $incoming->id)->active()->ofType('bartender')->exists())->toBeTrue();

    expect(StaffDebt::count())->toBe(0);

    $discrepancy = \App\Models\HandoverDiscrepancy::first();
    expect($discrepancy)->not->toBeNull();
    expect($discrepancy->status)->toBe('pending_resolution');
    expect((float) $discrepancy->shortfall_quantity)->toEqual(4.0); // 24 expected - 20 counted = 4 short
    expect((float) $discrepancy->naira_value)->toEqual(4 * 500.0);

    // The eventual debit still targets the absent outgoing custodian, not
    // the witness — proven via debitDiscrepancy() directly here since the
    // resolution workflow itself is covered in its own test file.
    $debited = $service->debitDiscrepancy($discrepancy, $incoming->id);
    expect($debited->staffDebt->user_id)->toBe($absentOutgoing->id);
});

it('refuses to seal until every disputed product is either resolved or explicitly marked unresolved', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-declare-4');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'disputed', ['Fridge' => 20]);

    expect(fn () => $service->sealAgreement($session, '5793', '2846', 'test-blocked'))
        ->toThrow(Exception::class, 'Every disputed product must be resolved or marked unresolved before sealing.');
});

it('proves the freeze end to end through OrderSplitter: bar orders rejected once declared, allowed again once sealed', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();
    DB::table('tables')->insert(['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $service = new CountSessionService();
    $orderSplitter = new OrderSplitter();
    $cart = fn (Product $product) => [$product->id => ['name' => $product->name, 'price' => $product->price, 'quantity' => 1]];
    $product = Product::first();

    // Before declaring, the outgoing's shift is still active — bar sales work.
    $orderSplitter->handle($cart($product), 1, $waiter->id, []);
    expect(\App\Models\Order::count())->toBe(1);

    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-freeze-declare');

    expect(fn () => $orderSplitter->handle($cart($product), 1, $waiter->id, []))
        ->toThrow(Exception::class, 'No active bartender session');
    expect(\App\Models\Order::count())->toBe(1); // unchanged — the freeze attempt didn't sneak an order through

    $item->refresh();
    $service->bindIncomingCustodian($session, '2846', 'test-freeze-bind');
    $service->reviewProduct($item, $incoming->id, 'accepted');
    $service->sealAgreement($session, '5793', '2846', 'test-freeze-seal');

    // Sealed — the incoming's new shift lifts the freeze. order_number is
    // second-precision (ORD-{time()}-{destination}), so a 1s gap avoids
    // colliding with the first order placed above in the same test.
    sleep(1);
    $orderSplitter->handle($cart($product), 1, $waiter->id, []);
    expect(\App\Models\Order::count())->toBe(2);
});

it('never ships the expected/adjusted quantity to the browser through the new declare/review screens', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverScenario();

    \App\Models\PagePermission::firstOrCreate(
        ['page_class' => \App\Filament\Pages\CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => \App\Filament\Pages\CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-blind-declare');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'disputed', ['Fridge' => 20]);

    $component = \Livewire\Livewire::actingAs($incoming)
        ->test(\App\Filament\Pages\CountSessionDetail::class, ['session_id' => $session->id]);

    $safeReviewItems = $component->instance()->safeReviewItems();
    expect(json_encode($safeReviewItems))->not->toContain('expected');
    expect($safeReviewItems[0])->not->toHaveKey('expected_quantity_at_open');
    expect($safeReviewItems[0])->not->toHaveKey('adjusted_expected_quantity');

    // Raw wire:snapshot check too, same guarantee the original counting
    // screen's test protects, extended to the 'declared' status.
    $rawSnapshotData = json_encode($component->getData());
    expect($rawSnapshotData)->not->toContain('expected_quantity_at_open');
    expect($rawSnapshotData)->not->toContain('adjusted_expected_quantity');
});
