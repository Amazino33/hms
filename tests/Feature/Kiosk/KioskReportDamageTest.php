<?php

use App\Models\Category;
use App\Models\DamageReport;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Regression coverage for a live production bug: the kiosk damage-report
 * screen rendered fine but was completely dead — search, the quantity
 * stepper, and Submit all did nothing. Root cause: <x-mobile.stepper
 * model="quantity" ...> assumes `quantity` already exists as an
 * Alpine-reactive variable, but nothing on the page ever declared it —
 * Livewire does NOT automatically expose public PHP properties as bare
 * Alpine globals (confirmed by comparing against pos.blade.php's working
 * cashDropAmount, which is wrapped in `x-data="{ cashDropAmount:
 * @entangle('cashDropAmount') }"`). Alpine threw an uncaught
 * "quantity is not defined" during initialization, which silently broke
 * every other interactive element on the page, not just the stepper.
 */
function reportDamageFixture(): array
{
    Role::firstOrCreate(['name' => 'bartender']);
    $bartender = User::factory()->create();
    $bartender->assignRole('bartender');

    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Star Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);

    return compact('bartender', 'product', 'bar');
}

it('entangles the quantity property so the stepper has a real Alpine-reactive variable to bind to', function () {
    ['bartender' => $bartender] = reportDamageFixture();

    $html = Livewire::actingAs($bartender)->test('kiosk-report-damage')->html();

    // The exact bug class: a bare Alpine reference to a Livewire property
    // with no entangle/declaration anywhere in scope. Compiled @entangle
    // output is a window.Livewire.find(...).entangle('quantity') call
    // embedded in the x-data — without it, quantity is undefined in Alpine.
    expect($html)->toContain(".entangle('quantity')");
    expect($html)->toMatch('/x-data="\{\s*quantity:/');
});

it('lets a bartender search, select a product, and submit a damage report end to end', function () {
    ['bartender' => $bartender, 'product' => $product] = reportDamageFixture();

    Livewire::actingAs($bartender)->test('kiosk-report-damage')
        ->set('search', 'Star')
        ->call('selectProduct', $product->id, $product->name)
        ->assertSet('productId', $product->id)
        ->set('quantity', 3)
        ->set('note', 'Dropped and broke on the floor.')
        ->call('submit')
        ->assertOk();

    expect(DamageReport::where('product_id', $product->id)->where('quantity', 3)->exists())->toBeTrue();
});
