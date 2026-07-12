<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Regression for a real production data-loss bug: the counting screen's
 * next() used to fire the save and advance currentIndex in the same tick
 * without awaiting it — a slow connection plus a fast tap on the following
 * product queued overlapping requests and could silently drop the earlier
 * product's figures, with the "Saving…" indicator giving no real signal
 * either way. The client-side fix (await before navigating, non-reentrant,
 * retry-then-error) can't be exercised by Pest directly since it's pure JS
 * timing, but the contract it depends on — recordCount() must return a
 * real true/false the JS can trust instead of always resolving as success
 * — is fully testable here.
 */
it('returns true from the page-level recordCount on a real successful save', function () {
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $outgoing = User::factory()->create();
    $outgoing->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $incoming = User::factory()->create();
    $incoming->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    $result = Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->set("subLocationInputs.{$item->id}.Fridge", 7)
        ->call('recordCount', $item->id);

    expect($result->instance()->recordCount($item->id))->toBeTrue();
    expect((float) $item->fresh()->counted_quantity)->toBe(7.0);
});

it('returns false from the page-level recordCount when the underlying save is rejected, instead of silently succeeding', function () {
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $outgoing = User::factory()->create();
    $outgoing->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    $incoming = User::factory()->create();
    $incoming->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    // Incoming is not the one allowed to record counts during a normal
    // handover — this must fail server-side, and the page method must
    // report that failure as `false`, not silently resolve as success.
    $component = Livewire::actingAs($incoming)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->set("subLocationInputs.{$item->id}.Fridge", 99);

    $ok = $component->instance()->recordCount($item->id);

    expect($ok)->toBeFalse();
    expect((float) $item->fresh()->counted_quantity ?? 0)->not->toBe(99.0);
});
