<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\ProcurementService;

function makeMainStore(): WareHouse
{
    return WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
}

it('commits a procurement with a crate line, converting to base units and deriving unit cost', function () {
    $store = makeMainStore();
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500,
        'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 12,
        'is_active' => true,
    ]);

    $procurement = app(ProcurementService::class)->commit(
        ['location_id' => $store->id, 'supplier_name' => 'ABC Distributors', 'purchased_at' => '2026-07-11'],
        [[
            'product_id' => $product->id,
            'entered_qty' => 2,
            'entered_unit' => 'purchase_unit',
            'line_total_cost' => 12000,
        ]],
        [],
        $user->id,
    );

    expect($procurement->reference)->toStartWith('PRC-');
    expect((float) $procurement->total_cost)->toBe(12000.0);

    $item = $procurement->items->first();
    expect((float) $item->base_qty)->toBe(24.0);
    expect($item->units_per_purchase_unit_snapshot)->toBe(12);
    expect((float) $item->unit_cost)->toBe(500.0);

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $store->id)->value('quantity'))->toBe(24.0);
    expect((float) $product->fresh()->last_cost_price)->toBe(500.0);
});

it('supports mixed crate + loose-bottle lines for the same product as two separate lines', function () {
    $store = makeMainStore();
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500,
        'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 12,
        'is_active' => true,
    ]);

    app(ProcurementService::class)->commit(
        ['location_id' => $store->id, 'purchased_at' => '2026-07-11'],
        [
            ['product_id' => $product->id, 'entered_qty' => 2, 'entered_unit' => 'purchase_unit', 'line_total_cost' => 12000],
            ['product_id' => $product->id, 'entered_qty' => 5, 'entered_unit' => 'base_unit', 'line_total_cost' => 2750],
        ],
        [],
        $user->id,
    );

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $store->id)->value('quantity'))->toBe(29.0);
});

it('creates an inline product with pack fields and flags it as staff-created', function () {
    $store = makeMainStore();
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $procurement = app(ProcurementService::class)->commit(
        ['location_id' => $store->id, 'purchased_at' => '2026-07-11'],
        [[
            'new_product' => [
                'name' => 'Brand New Drink',
                'category_id' => $category->id,
                'base_unit' => 'bottle',
                'purchase_unit_name' => 'crate',
                'units_per_purchase_unit' => 24,
            ],
            'entered_qty' => 1,
            'entered_unit' => 'purchase_unit',
            'line_total_cost' => 24000,
        ]],
        [],
        $user->id,
    );

    $product = Product::where('name', 'Brand New Drink')->first();
    expect($product)->not->toBeNull();
    expect($product->created_by_staff)->toBeTrue();
    expect($product->created_by)->toBe($user->id);
    expect((float) $procurement->items->first()->base_qty)->toBe(24.0);
});

it('commits an ingredient procurement line converting pack size to base units', function () {
    $store = makeMainStore();
    $user = User::factory()->create();
    $ingredient = Ingredient::create([
        'name' => 'Rice', 'sku' => 'ING-RICE', 'unit_name' => 'kg', 'quantity' => 0,
        'cost_per_unit' => 0, 'category' => 'Dry Goods',
        'purchase_unit_name' => 'bag', 'units_per_purchase_unit' => 25,
    ]);

    app(ProcurementService::class)->commit(
        ['location_id' => $store->id, 'purchased_at' => '2026-07-11'],
        [],
        [[
            'ingredient_id' => $ingredient->id,
            'entered_qty' => 2,
            'entered_unit' => 'purchase_unit',
            'line_total_cost' => 30000,
        ]],
        $user->id,
    );

    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->where('warehouse_id', $store->id)->value('quantity'))->toBe(50.0);
    expect((float) $ingredient->fresh()->cost_per_unit)->toBe(600.0);
});
