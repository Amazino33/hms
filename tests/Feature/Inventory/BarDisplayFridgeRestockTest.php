<?php

use App\Filament\Pages\BarDisplay;
use App\Models\Category;
use App\Models\FridgeRestockMark;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function seedReviewedBarCountForRestockPanel(WareHouse $bar, Product $product, float $fridgeQuantity): void
{
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->where('product_id', $product->id)->first();

    $service->recordCount($item, ['Fridge' => $fridgeQuantity]);
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

it('shows a below-par product on the BarDisplay restock panel and lets the bartender mark it restocked', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Tiger Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true, 'fridge_par' => 12]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    seedReviewedBarCountForRestockPanel($bar, $product, 3);

    Livewire::actingAs($admin)
        ->test(BarDisplay::class)
        ->assertSee('Restock Fridge')
        ->assertSee('Tiger Beer')
        ->call('markRestocked', $product->id);

    $mark = FridgeRestockMark::where('product_id', $product->id)->where('warehouse_id', $bar->id)->first();
    expect($mark)->not->toBeNull();
    expect((float) $mark->marked_quantity)->toEqual(12.0);

    Livewire::actingAs($admin)
        ->test(BarDisplay::class)
        ->assertDontSee('Tiger Beer');
});

it('shows nothing on the restock panel when every product is above par or unset', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);

    Livewire::actingAs($admin)
        ->test(BarDisplay::class)
        ->assertSee('Nothing below par');
});
