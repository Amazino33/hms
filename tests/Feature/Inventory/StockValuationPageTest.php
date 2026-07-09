<?php

use App\Filament\Pages\StockValuation;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;

/**
 * Regression test: ->summarize(Sum::make()) on total_cost_value/
 * total_sales_value used to throw a SQL error, because those columns only
 * exist as a PHP ->state() closure, not a real database column — Filament's
 * default summarizer tries to run SUM() on them directly in SQL. Fixed via
 * Sum::make()->using(), which computes the total in PHP instead.
 */
it('renders the stock valuation table with a correct total row, with no SQL error', function () {
    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    PagePermission::firstOrCreate(
        ['page_class' => StockValuation::class, 'role_name' => 'super_admin'],
        ['page_class' => StockValuation::class, 'page_name' => 'Stock Level & Valuation', 'role_name' => 'super_admin']
    );

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 300, 'category_id' => $category->id, 'is_active' => true]);
    $coke = Product::create(['name' => 'Coke', 'price' => 200, 'cost_price' => 100, 'category_id' => $category->id, 'is_active' => true]);

    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);
    InventoryItem::create(['product_id' => $coke->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);

    // Beer: 10 * 300 = 3000 cost, 10 * 500 = 5000 sales
    // Coke: 5 * 100 = 500 cost, 5 * 200 = 1000 sales
    // Totals: 3500 cost, 6000 sales

    Livewire::actingAs($admin)
        ->test(StockValuation::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('3,500.00')
        ->assertSee('6,000.00');
});
