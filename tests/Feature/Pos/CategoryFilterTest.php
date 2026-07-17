<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Category::has('products') alone hid any category whose sellable items
 * are all cooked-to-order MenuItems (a typical "Food" category) rather
 * than simple Products (a typical "Drinks" category) — the category tab
 * never appeared on the order screen even though its dishes show under
 * "All". Categories are matched if they have either.
 */
it('shows a category filter tab for a category whose items are menu items, not products', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $food = Category::create(['name' => 'Food', 'type' => 'food']);
    MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'JOLLOF-1', 'category_id' => $food->id, 'sale_price' => 2000, 'available_for_sale' => true]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->assertSee('Food');
});

it('still shows a category filter tab for a category with plain products', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->assertSee('Drinks');
});

it('does not show a category filter tab for a category with neither products nor menu items', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    Category::create(['name' => 'Empty Category', 'type' => 'food']);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->assertDontSee('Empty Category');
});

/**
 * A food category has menu items but zero Products (they're two different
 * models) — the loading-skeleton block only checked $products->isEmpty(),
 * so it kept rendering 8 fake placeholder tiles even after real data
 * loaded, on every screen size, forever, for any menu-item-only category.
 */
it('shows real menu items in a food category with zero products, without leaving stray loading-skeleton tiles behind', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $food = Category::create(['name' => 'Food', 'type' => 'food']);
    MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'JOLLOF-1', 'category_id' => $food->id, 'sale_price' => 2000, 'available_for_sale' => true]);

    $component = Livewire::actingAs($waiter)
        ->test('pos')
        ->set('activeCategoryId', $food->id)
        ->call('loadProducts');

    $component->assertSee('Jollof Rice');
    // "animate-pulse" alone also matches an unrelated online-status dot
    // elsewhere on this page — this is the skeleton tile's distinguishing
    // class combination specifically.
    $component->assertDontSee('animate-pulse bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl', false);
});

it('still shows the loading skeleton while products genuinely have not loaded yet', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);

    // deferProducts defaults to true for a non-kiosk session, and
    // loadProducts() is never called here — mirrors the real gap between
    // initial mount and wire:init firing.
    Livewire::actingAs($waiter)
        ->test('pos')
        ->assertDontSee('Beer')
        ->assertSee('animate-pulse bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl', false);
});

/**
 * Every category tab renders with near-identical markup across all three
 * (desktop/mobile/kiosk) layouts and none had a wire:key — the exact
 * class of gap that lets a keyless Livewire morph misattribute state
 * across re-renders after a category switch.
 */
it('gives each category tab a stable wire:key in the desktop and mobile layouts', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);

    $html = Livewire::actingAs($waiter)->test('pos')->html();

    expect($html)->toContain('wire:key="category-tab-desktop-' . $drinks->id . '"');
    expect($html)->toContain('wire:key="category-tab-mobile-' . $drinks->id . '"');
    expect($html)->toContain('wire:key="category-tab-mobile-all"');
});

it('gives each category tab a stable wire:key in the kiosk layout', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);

    // pos's own boot() redirects (and skips render entirely) unless the
    // staff_pin guard specifically is authenticated whenever a kiosk
    // session exists — matching the real kiosk/staff-phone middleware
    // stack, not the default 'web' guard actingAs() would use.
    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => 1]);

    $html = Livewire::test('pos')->html();

    expect($html)->toContain('wire:key="category-tab-kiosk-' . $drinks->id . '"');
    expect($html)->toContain('wire:key="category-tab-kiosk-all"');
});
