<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
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
