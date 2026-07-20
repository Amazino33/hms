<?php

use App\Filament\Pages\CountSessionDetail;
use App\Filament\Pages\HandoverDiscrepancies;
use App\Filament\Pages\MyStoreCount;
use App\Models\Category;
use App\Models\HandoverDiscrepancy;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\StaffDebt;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\PinAuthService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The storekeeper's unwitnessed solo store count: blind entry (reused
 * as-is from the bartender/chef count architecture), single-PIN submit
 * that snaps stock and creates discrepancy rows immediately (never gated
 * on super-admin approval), and per-line ruling via the same
 * HandoverDiscrepancies queue bartender/chef shortages already use —
 * extended here to also carry overage rows, which a handover never
 * creates.
 */
function storeCountScenario(int $liveStockQuantity = 20): array
{
    static $call = 0;
    $call++;

    $store = WareHouse::create(['name' => 'Main Store ' . uniqid(), 'type' => 'storage', 'is_active' => 1]);
    $category = Category::firstOrCreate(['name' => 'Store Stock'], ['type' => 'drink']);
    $product = Product::create(['name' => 'Star Beer ' . uniqid(), 'price' => 700, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $store->id, 'quantity' => $liveStockQuantity]);

    Role::firstOrCreate(['name' => 'storekeeper']);
    Role::firstOrCreate(['name' => 'super_admin']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole('storekeeper');

    $pin = (string) (3140 + $call);
    (new PinAuthService())->setPin($storekeeper, $pin);

    return compact('store', 'category', 'product', 'storekeeper', 'pin');
}

it('opens a solo store count with no outgoing/incoming, accountable to whoever opened it', function () {
    ['store' => $store, 'storekeeper' => $storekeeper] = storeCountScenario();

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);

    expect($session->outgoing_user_id)->toBeNull();
    expect($session->incoming_user_id)->toBeNull();
    expect($session->accountableUserId())->toBe($storekeeper->id);
    expect($session->isHandover())->toBeFalse();
});

it('never exposes expected quantities to a solo counter — safeCountItems carries no expected/adjusted keys', function () {
    ['store' => $store, 'storekeeper' => $storekeeper] = storeCountScenario(20);

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'storekeeper'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'storekeeper']
    );

    $component = Livewire::actingAs($storekeeper)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $items = $component->instance()->safeCountItems();

    expect($items)->toHaveCount(1);
    expect($items[0])->not->toHaveKey('expected_quantity_at_open');
    expect($items[0])->not->toHaveKey('adjusted_expected_quantity');

    // The real page render (not just the PHP array) must not leak the
    // live quantity (20) anywhere in the HTML either.
    $html = $component->html();
    expect($html)->not->toContain('"expected_quantity_at_open"');
});

it('refuses to let anyone but the opener record counts on a solo session', function () {
    ['store' => $store, 'storekeeper' => $storekeeper] = storeCountScenario();
    $otherStorekeeper = User::factory()->create();
    $otherStorekeeper->assignRole('storekeeper');

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();

    expect(fn () => (new CountSessionService())->recordCount($item, ['Shelf A' => 5], $otherStorekeeper->id))
        ->toThrow(Exception::class, 'Only the person who opened this count can record it.');

    // The actual opener is unaffected.
    (new CountSessionService())->recordCount($item, ['Shelf A' => 5], $storekeeper->id);
    expect($item->fresh()->counted_quantity)->toEqualWithDelta(5.0, 0.001);
});

it('refuses to submit a solo count with a PIN that does not match the person who counted', function () {
    ['store' => $store, 'storekeeper' => $storekeeper] = storeCountScenario();

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 20], $storekeeper->id);

    expect(fn () => (new CountSessionService())->submitSoloCount($session->fresh(), '0000', 'store-count-test-' . uniqid()))
        ->toThrow(Exception::class, 'That PIN does not match the person who counted.');

    expect($session->fresh()->status)->toBe('counting');
});

it('snaps stock and moves straight to reviewed on a correct single-PIN submit, with no debt yet', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(20);

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 15], $storekeeper->id);

    $sealed = (new CountSessionService())->submitSoloCount($session->fresh(), $pin, 'store-count-test-' . uniqid());

    expect($sealed->status)->toBe('reviewed');
    expect((float) $sealed->total_shortage_value)->toBe(3500.0); // 5 short @ 700

    // Stock is trued up immediately — not waiting on any ruling.
    expect((float) InventoryItem::where('product_id', $item->product_id)->where('warehouse_id', $store->id)->value('quantity'))->toBe(15.0);

    // No StaffDebt yet — only a discrepancy, exactly like a handover.
    expect(StaffDebt::count())->toBe(0);
    $discrepancy = HandoverDiscrepancy::first();
    expect($discrepancy)->not->toBeNull();
    expect($discrepancy->direction)->toBe('shortage');
    expect($discrepancy->status)->toBe('pending_resolution');
    expect((float) $discrepancy->naira_value)->toBe(3500.0);
});

it('creates a count_session_shortfall StaffDebt against the storekeeper when the shortage is debited', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(20);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 15], $storekeeper->id);
    (new CountSessionService())->submitSoloCount($session->fresh(), $pin, 'store-count-test-' . uniqid());

    $discrepancy = HandoverDiscrepancy::first();
    (new CountSessionService())->debitDiscrepancy($discrepancy, $superAdmin->id);

    $debt = StaffDebt::first();
    expect($debt)->not->toBeNull();
    expect($debt->user_id)->toBe($storekeeper->id);
    expect($debt->reason)->toBe('count_session_shortfall');
    expect((float) $debt->amount)->toBe(3500.0);
    expect($discrepancy->fresh()->status)->toBe('debited');
});

it('resolves a shortage without a debit via write-off, leaving no StaffDebt', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(20);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 15], $storekeeper->id);
    (new CountSessionService())->submitSoloCount($session->fresh(), $pin, 'store-count-test-' . uniqid());

    $discrepancy = HandoverDiscrepancy::first();
    (new CountSessionService())->writeOffDiscrepancy($discrepancy, 'Miscounted last week, verified against delivery note.', $superAdmin->id);

    expect(StaffDebt::count())->toBe(0);
    expect($discrepancy->fresh()->status)->toBe('written_off');
});

it('creates an overage discrepancy on a solo count, unlike a handover which only tracks it as a session total', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(20);

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 26], $storekeeper->id); // 6 more than live stock

    $sealed = (new CountSessionService())->submitSoloCount($session->fresh(), $pin, 'store-count-test-' . uniqid());

    expect((float) $sealed->total_overage_quantity)->toBe(6.0);
    expect((float) $sealed->total_shortage_value)->toBe(0.0);

    $discrepancy = HandoverDiscrepancy::first();
    expect($discrepancy)->not->toBeNull();
    expect($discrepancy->direction)->toBe('overage');
    expect($discrepancy->status)->toBe('pending_resolution');
    expect(StaffDebt::count())->toBe(0);

    // Stock is already trued up to the counted figure regardless of ruling.
    expect((float) InventoryItem::where('product_id', $item->product_id)->where('warehouse_id', $store->id)->value('quantity'))->toBe(26.0);
});

it('refuses to debit or write off an overage — only acknowledge or pend investigation apply', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(20);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 26], $storekeeper->id);
    (new CountSessionService())->submitSoloCount($session->fresh(), $pin, 'store-count-test-' . uniqid());

    $discrepancy = HandoverDiscrepancy::first();
    $service = new CountSessionService();

    expect(fn () => $service->debitDiscrepancy($discrepancy, $superAdmin->id))->toThrow(Exception::class);
    expect(fn () => $service->writeOffDiscrepancy($discrepancy, 'n/a', $superAdmin->id))->toThrow(Exception::class);

    $acknowledged = $service->acknowledgeOverage($discrepancy, $superAdmin->id);
    expect($acknowledged->status)->toBe('acknowledged');
    expect($acknowledged->resolved_by)->toBe($superAdmin->id);
    expect(StaffDebt::count())->toBe(0);
});

it('refuses to acknowledge a shortage line — that is a shortage-only guard in reverse', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(20);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 15], $storekeeper->id);
    (new CountSessionService())->submitSoloCount($session->fresh(), $pin, 'store-count-test-' . uniqid());

    $discrepancy = HandoverDiscrepancy::first();

    expect(fn () => (new CountSessionService())->acknowledgeOverage($discrepancy, $superAdmin->id))->toThrow(Exception::class);
});

it('does not create a discrepancy row for an overage on a real dual-PIN handover — unchanged behavior', function () {
    ['session' => $session] = sealedHandoverScenario(20, 24); // counted more than live stock

    expect(HandoverDiscrepancy::count())->toBe(0);
    expect((float) $session->fresh()->total_overage_quantity)->toBe(4.0);
});

it('defaults every discrepancy created by a real dual-PIN handover seal to direction=shortage', function () {
    sealedHandoverScenario(24, 20); // counted less than live stock

    $discrepancy = HandoverDiscrepancy::first();
    expect($discrepancy->direction)->toBe('shortage');
    expect($discrepancy->isShortage())->toBeTrue();
});

it('drives a full solo store count end to end through MyStoreCount and CountSessionDetail', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(10);

    foreach (['App\Filament\Pages\MyStoreCount', 'App\Filament\Pages\CountSessionDetail'] as $pageClass) {
        PagePermission::firstOrCreate(
            ['page_class' => $pageClass, 'role_name' => 'storekeeper'],
            ['page_class' => $pageClass, 'page_name' => $pageClass, 'role_name' => 'storekeeper']
        );
    }

    $entry = Livewire::actingAs($storekeeper)->test(MyStoreCount::class);
    $entry->call('startCount', 'product')->assertRedirect();

    $session = \App\Models\CountSession::where('type', 'main_store_stocktake')->where('opened_by', $storekeeper->id)->first();
    expect($session)->not->toBeNull();

    $detail = Livewire::actingAs($storekeeper)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $item = $session->items()->first();
    $ok = $detail->instance()->recordCount($item->id, ['Shelf A' => 10]);
    expect($ok)->toBeTrue();

    $submitted = $detail->instance()->submitSoloCount($pin);
    expect($submitted)->toBeTrue();
    expect($session->fresh()->status)->toBe('reviewed');
});

it('denies the storekeeper access to the HandoverDiscrepancies approval queue', function () {
    ['storekeeper' => $storekeeper] = storeCountScenario();

    expect(Livewire::actingAs($storekeeper)->test(HandoverDiscrepancies::class)->instance())->toBeNull();
});

it('still allows manager/admin/super_admin to see and rule on store-count discrepancies, same as bartender/chef ones', function () {
    ['store' => $store, 'storekeeper' => $storekeeper, 'pin' => $pin] = storeCountScenario(20);
    Role::firstOrCreate(['name' => 'manager']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    PagePermission::firstOrCreate(
        ['page_class' => HandoverDiscrepancies::class, 'role_name' => 'manager'],
        ['page_class' => HandoverDiscrepancies::class, 'page_name' => 'Handover Discrepancies', 'role_name' => 'manager']
    );

    $session = (new CountSessionService())->openSession('main_store_stocktake', $store->id, $storekeeper->id);
    $item = $session->items()->first();
    (new CountSessionService())->recordCount($item, ['Shelf A' => 15], $storekeeper->id);
    (new CountSessionService())->submitSoloCount($session->fresh(), $pin, 'store-count-test-' . uniqid());

    $discrepancy = HandoverDiscrepancy::first();

    Livewire::actingAs($manager)
        ->test(HandoverDiscrepancies::class)
        ->assertCanSeeTableRecords([$discrepancy])
        ->callTableAction('debit', $discrepancy);

    expect($discrepancy->fresh()->status)->toBe('debited');
});
