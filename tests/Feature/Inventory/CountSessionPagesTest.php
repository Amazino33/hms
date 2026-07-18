<?php

use App\Filament\Pages\CountSessionDetail;
use App\Filament\Pages\CountSessions;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StaffDebt;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use Livewire\Livewire;

it('lists open count sessions on the Count Sessions page', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $manager->id, $outgoing->id, $incoming->id);

    Livewire::actingAs($manager)
        ->test(CountSessions::class)
        ->assertCanSeeTableRecords([$session])
        ->assertSee('Bar Handover')
        ->assertSee('Counting');
});

it('never ships the expected quantity to the browser while a session is still in the counting phase', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $superAdmin = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole($superAdmin);
    $incoming = User::factory()->create();
    $incoming->assignRole($superAdmin);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    expect((float) $item->expected_quantity_at_open)->toEqual(24.0);

    $component = Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id]);

    // Blind counting is the control this test protects: the expected
    // quantity must be genuinely absent from the client-side payload, not
    // merely unprinted by the Blade template — a curious counter reading
    // view-source or the wire:snapshot attribute must not be able to see
    // it. Assert against the raw serialized snapshot data, not the HTML.
    $rawSnapshotData = json_encode($component->getData());

    expect($rawSnapshotData)->not->toContain('expected_quantity_at_open');
    expect($rawSnapshotData)->not->toContain('adjusted_expected_quantity');
});

it('renders the one-product-at-a-time counting flow with the product name and number pad, no expected quantity anywhere in the safe item data', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $superAdmin = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole($superAdmin);
    $incoming = User::factory()->create();
    $incoming->assignRole($superAdmin);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);

    $component = Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->assertSee('Enter')
        ->assertSee('Previous')
        ->assertSee('Next');

    // safeCountItems() is what actually reaches the browser (via @js() in
    // the Blade view, not a public property) — assert directly against its
    // return value, the same blind-counting guarantee the snapshot-level
    // test above protects, from the other angle.
    $safeItems = $component->instance()->safeCountItems();
    expect($safeItems)->toHaveCount(1);
    expect($safeItems[0]['name'])->toBe('Heineken');
    expect($safeItems[0])->not->toHaveKey('expected_quantity_at_open');
    expect($safeItems[0])->not->toHaveKey('adjusted_expected_quantity');
    expect(json_encode($safeItems))->not->toContain('expected');
});

it('returns no safe count items once a session has left the counting phase', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $superAdmin = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole($superAdmin);
    $incoming = User::factory()->create();
    $incoming->assignRole($superAdmin);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $countService = new CountSessionService();
    $countService->confirmOutgoing($session, $outgoing->id);
    $countService->confirmIncoming($session, $incoming->id);
    $countService->submitForReview($session->fresh());

    $component = Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id]);

    expect($component->instance()->safeCountItems())->toBe([]);
});

it('walks a full bar handover session end to end through the detail page', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $superAdmin = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole($superAdmin);
    $incoming = User::factory()->create();
    $incoming->assignRole($superAdmin);
    $manager = User::factory()->create();
    $manager->assignRole($superAdmin);

    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    $component = Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('recordCount', $item->id, ['Fridge' => 20])
        ->call('confirmOutgoing');

    Livewire::actingAs($incoming)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('confirmIncoming')
        ->call('submitForReview');

    expect($session->fresh()->status)->toBe('pending_review');

    Livewire::actingAs($manager)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->set("reviewDecisions.{$item->id}", 'accountability')
        ->set("reviewNotes.{$item->id}", 'Bartender shortfall')
        ->call('decideItem', $item->id)
        ->call('finalizeReview');

    expect($session->fresh()->status)->toBe('reviewed');
    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
    expect(InventoryTransaction::where('type', 'adjustment')->count())->toBe(1);

    $debt = StaffDebt::first();
    expect($debt)->not->toBeNull();
    expect($debt->user_id)->toBe($outgoing->id);
    expect((float) $debt->amount)->toEqual(2000.0);
});

it('binds ?session_id= on a real HTTP GET request, not just a Livewire::test() mount argument', function () {
    // Regression test: Livewire::test(CountSessionDetail::class, ['session_id'
    // => $id]) injects the mount() parameter directly and always worked —
    // it never actually exercised how Filament resolves a real
    // /admin/count-session-detail?session_id=X request from a browser,
    // where the query string silently failed to reach mount() at all,
    // making every real visitor bounce straight back to the session list
    // (MyCount's "Start Handover Count" redirect, the admin list's row
    // links, all of it) despite every Livewire-level test passing.
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $session = (new CountSessionService())->openSession('main_store_stocktake', $bar->id, $manager->id);

    $response = $this->actingAs($manager)->get("/admin/count-session-detail?session_id={$session->id}");

    $response->assertOk();
    $response->assertSee('Beer');
});

it('shows a manager the final comparison of counted vs expected stock once a handover is sealed', function () {
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new \App\Services\PinAuthService();
    $pinAuth->setPin($outgoing, '6284');
    $pinAuth->setPin($incoming, '3971');

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 20], $outgoing->id); // 4 short of 24
    $session = $service->declare($session, '6284', 'test-manager-view-declare');
    $session = $service->bindIncomingCustodian($session, '3971', 'test-manager-view-bind');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');
    $session = $service->sealAgreement($session, '6284', '3971', 'test-manager-view-seal');

    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $response = $this->actingAs($manager)->get("/admin/count-session-detail?session_id={$session->id}");

    $response->assertOk();
    $response->assertSee('Final Comparison');
    $response->assertSee('24'); // expected — bar counts render as whole numbers, not "24.00"
    $response->assertSee('20'); // counted
    $response->assertSee('Accepted');
});

it('gives the Seal the Agreement panel a stable wire:key on each pad, surviving an unrelated Livewire re-render', function () {
    // Regression test for a live incident: reviewing items (Accept/Dispute/
    // Next/Previous) re-renders the page server-side. The dual-seal panel
    // sits alongside that reviewer, not inside its wire:ignore wrapper, so
    // without its own stable identity, that unrelated re-render orphaned
    // its Alpine scope — the browser console showed "pressed is not
    // defined" / "submitting is not defined" thrown from inside the pad's
    // own :class bindings, and the seal got stuck on "Confirming…" forever.
    // Asserting the compiled wire:key markup is present is what would have
    // caught this before it reached production — a passing "it seals
    // correctly" test never exercises the browser's own DOM morph at all.
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new \App\Services\PinAuthService();
    $pinAuth->setPin($outgoing, '6284');
    $pinAuth->setPin($incoming, '3971');

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '6284', 'test-seal-key-declare');
    $session = $service->bindIncomingCustodian($session, '3971', 'test-seal-key-bind');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    // Viewed by a manager (matching this file's existing convention for
    // rendering CountSessionDetail — see "shows a manager the final
    // comparison" above) rather than the bartender directly: a bare
    // 'bartender' role has no PagePermission grant for this admin page in
    // a fresh test database, only whatever production has actually seeded.
    // Irrelevant to what this test checks — the markup renders the same
    // regardless of viewer, once readyToSeal() is true.
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $html = Livewire::actingAs($manager)
        ->test(CountSessionDetail::class, ['session_id' => $session->fresh()->id])
        ->assertSee('Seal the Agreement')
        ->html();

    expect($html)->toContain("wire:key=\"dual-seal-{$session->id}\"");
    expect($html)->toContain("wire:key=\"dual-seal-{$session->id}-first\"");
});

it('flags a shortfall on the admin Count Sessions list once a handover with an accountability decision is reviewed', function () {
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new \App\Services\PinAuthService();
    $pinAuth->setPin($outgoing, '6284');
    $pinAuth->setPin($incoming, '3971');

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 20], $outgoing->id);
    $session = $service->declare($session, '6284', 'test-list-shortfall-declare');
    $session = $service->bindIncomingCustodian($session, '3971', 'test-list-shortfall-bind');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');
    $service->sealAgreement($session, '6284', '3971', 'test-list-shortfall-seal');

    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    Livewire::actingAs($manager)
        ->test(CountSessions::class)
        ->assertSee('Yes'); // the Shortfall? column badge
});
