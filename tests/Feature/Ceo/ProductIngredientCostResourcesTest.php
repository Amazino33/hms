<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientTransaction;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WareHouse;
use Spatie\Permission\Models\Role;

function seedCeoCostFixture(): array
{
    $ceo = User::factory()->create();
    $ceo->assignRole(Role::firstOrCreate(['name' => 'ceo']));

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 800, 'cost_price' => 500, 'is_active' => true,
    ]);

    $ingredient = Ingredient::create([
        'name' => 'Rice', 'sku' => 'ING-RICE', 'unit_name' => 'kg', 'quantity' => 10,
        'cost_per_unit' => 300, 'category' => 'Grains',
    ]);

    $menuItem = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MENU-JOLLOF', 'category_id' => $category->id, 'type' => 'food', 'sale_price' => 2500]);
    Recipe::create(['menu_item_id' => $menuItem->id, 'ingredient_id' => $ingredient->id, 'quantity_needed' => 2]);

    $warehouse = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    IngredientTransaction::create([
        'ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id, 'type' => 'usage',
        'quantity' => 2, 'cost_per_unit' => 300, 'user_id' => $ceo->id,
    ]);

    return compact('ceo', 'product', 'ingredient', 'menuItem');
}

it('shows product selling price, cost price, and margin to the ceo', function () {
    ['ceo' => $ceo] = seedCeoCostFixture();

    $response = $this->actingAs($ceo)->get('/ceo/products');

    $response->assertSuccessful();
    $response->assertSee('Star Beer');
    $response->assertSee('800.00');
    $response->assertSee('500.00');
    $response->assertSee('300.00'); // margin (800 - 500)
});

it('shows ingredient cost per unit to the ceo', function () {
    ['ceo' => $ceo] = seedCeoCostFixture();

    $response = $this->actingAs($ceo)->get('/ceo/ingredients');

    $response->assertSuccessful();
    $response->assertSee('Rice');
    $response->assertSee('300.00');
});

it('shows menu item sale price, recipe cost, and margin to the ceo', function () {
    ['ceo' => $ceo] = seedCeoCostFixture();

    $response = $this->actingAs($ceo)->get('/ceo/menu-items');

    $response->assertSuccessful();
    $response->assertSee('Jollof Rice');
    $response->assertSee('2,500.00');
    $response->assertSee('600.00'); // recipe cost: 2kg x 300
    $response->assertSee('1,900.00'); // margin: 2500 - 600
});

it('shows kitchen ingredient stock movement to the ceo', function () {
    ['ceo' => $ceo] = seedCeoCostFixture();

    $response = $this->actingAs($ceo)->get('/ceo/ingredient-transactions');

    $response->assertSuccessful();
    $response->assertSee('Rice');
    $response->assertSee('Kitchen');
    $response->assertSee('Usage');
});

it('blocks a plain waiter from every new ceo cost resource', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)->get('/ceo/products')->assertForbidden();
    $this->actingAs($waiter)->get('/ceo/ingredients')->assertForbidden();
    $this->actingAs($waiter)->get('/ceo/menu-items')->assertForbidden();
    $this->actingAs($waiter)->get('/ceo/ingredient-transactions')->assertForbidden();
});
