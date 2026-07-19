<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

it('renders the stock overview page showing per-warehouse product stock by default', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Zobo Drink', 'price' => 300, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 17]);

    $response = $this->actingAs($admin)->get('/admin/stock-overview');

    $response->assertStatus(200);
    $response->assertSee('Zobo Drink');
    $response->assertSee('17');
});

it('shows ingredient stock when toggled to the ingredients view', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $warehouse = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $ingredient = Ingredient::create(['name' => 'Tomato', 'sku' => 'ING-TOMATO', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 2, 'category' => 'Vegetables']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id, 'quantity' => 8]);

    \Livewire\Livewire::actingAs($admin)
        ->test(\App\Filament\Pages\StockOverview::class)
        ->call('setViewMode', 'ingredients')
        ->assertSee('Tomato')
        ->assertSee('8');
});

it('grants a storekeeper access to Stock Overview so she can see what she has before transferring or procuring', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $response = $this->actingAs($storekeeper)->get('/admin/stock-overview');

    $response->assertStatus(200);
});

it('does not grant an unrelated role (waiter) access to Stock Overview', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $response = $this->actingAs($waiter)->get('/admin/stock-overview');

    $response->assertForbidden();
});
