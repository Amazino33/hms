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
 * Regression for a real production bug: a solo opening count (nobody was
 * on shift yet, so outgoing_user_id is null) was wrongly treated by
 * isHandoverWithSuccessor() as a real two-party handover. iAmCounter()
 * then compared outgoing_user_id (null) against auth()->id(), which can
 * never match anyone — so the bartender who opened the count got stuck
 * forever on "Waiting for  to finish counting and declare," unable to
 * even see the counting screen, let alone record anything.
 */
it('lets the incoming custodian actually count and submit a solo opening session through the real page, not get stuck waiting', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    // No outgoing custodian at all — a genuine first-of-day solo count.
    $session = (new CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    expect($session->isHandoverWithSuccessor())->toBeFalse();

    $item = $session->items()->first();

    $component = Livewire::actingAs($bartender)
        ->test(CountSessionDetail::class, ['session_id' => $session->id]);

    // The bug: this used to show "Waiting for  to finish counting and
    // declare." instead of the actual counting screen.
    $component->assertDontSee('Waiting for')
        ->assertSee('Confirm as Incoming Custodian');

    expect($component->instance()->iAmCounter())->toBeTrue();

    $component
        ->set("subLocationInputs.{$item->id}.Fridge", 24)
        ->call('recordCount', $item->id)
        ->call('confirmIncoming')
        ->call('submitForReview');

    expect($session->fresh()->status)->toBe('pending_review');
    expect((float) $item->fresh()->counted_quantity)->toBe(24.0);
});
