<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\ProcurementService;
use Spatie\Permission\Models\Role;

function seedCeoProcurementFixture(): array
{
    $store = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $recorder = User::factory()->create();

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500,
        'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 12,
        'is_active' => true,
    ]);

    $ingredient = Ingredient::create([
        'name' => 'Tomato', 'sku' => 'ING-TOMATO', 'unit_name' => 'kg', 'quantity' => 0,
        'cost_per_unit' => 2, 'category' => 'Vegetables',
    ]);

    $procurement = app(ProcurementService::class)->commit(
        ['location_id' => $store->id, 'supplier_name' => 'ABC Distributors', 'purchased_at' => '2026-07-11'],
        [[
            'product_id' => $product->id,
            'entered_qty' => 2,
            'entered_unit' => 'purchase_unit',
            'line_total_cost' => 12000,
        ]],
        [[
            'ingredient_id' => $ingredient->id,
            'entered_qty' => 5,
            'entered_unit' => 'base_unit',
            'line_total_cost' => 10,
        ]],
        $recorder->id,
    );

    return compact('procurement', 'product', 'ingredient', 'recorder');
}

it('renders a procurement detail page for the ceo, showing product and ingredient line items', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    ['procurement' => $procurement] = seedCeoProcurementFixture();

    $response = $this->actingAs($ceo)->get("/ceo/procurements/{$procurement->id}");

    $response->assertSuccessful();
    $response->assertSee('Star Beer');
    $response->assertSee('Tomato');
    $response->assertSee('ABC Distributors');
});

it('blocks a plain waiter from the ceo procurement detail page', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    ['procurement' => $procurement] = seedCeoProcurementFixture();

    $this->actingAs($waiter)->get("/ceo/procurements/{$procurement->id}")->assertForbidden();
});

it('links each row on the procurement list to its own detail page', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    ['procurement' => $procurement] = seedCeoProcurementFixture();

    $response = $this->actingAs($ceo)->get('/ceo/procurements');

    $response->assertSuccessful();
    $response->assertSee("/ceo/procurements/{$procurement->id}", false);
});
