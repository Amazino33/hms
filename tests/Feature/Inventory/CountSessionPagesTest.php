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
        ->set("subLocationInputs.{$item->id}.Fridge", 20)
        ->call('recordCount', $item->id)
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
