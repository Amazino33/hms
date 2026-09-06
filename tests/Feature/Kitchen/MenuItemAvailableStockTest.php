<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\WareHouse;
use App\Services\InventoryService;

/**
 * The POS food tiles used to read the legacy `ingredients.quantity`
 * column, which nothing has updated since InventoryService moved to
 * per-warehouse rows — so the portions figure was frozen at whatever the
 * ingredient was created with, while products beside them showed live
 * stock. These pin the accessor to the same source the deduction and the
 * availability gate already use.
 */
beforeEach(function () {
    // getBarWarehouseId()/getKitchenWarehouseId() are Cache::remember'd and
    // the cache driver is array across tests in the same process — a stale
    // id from an earlier test would point this at the wrong warehouse.
    Cache::flush();

    Company::create(['id' => 1, 'name' => 'Test Hotel', 'enforce_kitchen_ingredient_stock' => true]);

    // getKitchenWarehouseId() takes the SECOND consumer warehouse by id.
    $this->bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $this->kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $this->food = Category::create(['name' => 'Mains', 'type' => 'food']);
});

function makeDish(string $name, array $recipeSpec): MenuItem
{
    $menuItem = MenuItem::create([
        'name' => $name, 'sku' => 'SKU-'.Str::random(6),
        'category_id' => test()->food->id, 'sale_price' => 2500, 'available_for_sale' => true,
    ]);

    foreach ($recipeSpec as $spec) {
        Recipe::create([
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $spec['ingredient']->id,
            'quantity_needed' => $spec['needed'],
        ]);
    }

    return $menuItem->fresh(['recipes.ingredient.inventory']);
}

function makeIngredient(string $name, float $legacyQuantity, ?float $kitchenQuantity, int $kitchenWarehouseId): Ingredient
{
    $ingredient = Ingredient::create([
        'name' => $name, 'sku' => 'ING-'.Str::random(6), 'unit_name' => 'kg',
        'quantity' => $legacyQuantity, 'cost_per_unit' => 500, 'category' => 'Grains',
    ]);

    if ($kitchenQuantity !== null) {
        IngredientInventoryItem::create([
            'ingredient_id' => $ingredient->id,
            'warehouse_id' => $kitchenWarehouseId,
            'quantity' => $kitchenQuantity,
        ]);
    }

    return $ingredient;
}

it('confirms the kitchen warehouse resolves to the one the test set up', function () {
    expect(InventoryService::getKitchenWarehouseId())->toBe($this->kitchen->id);
});

/**
 * The regression itself: the legacy column says there is plenty, the
 * warehouse says there is none. The tile must believe the warehouse.
 */
it('reads live kitchen warehouse stock, not the frozen legacy ingredients.quantity column', function () {
    $rice = makeIngredient('Rice', legacyQuantity: 500, kitchenQuantity: 0, kitchenWarehouseId: $this->kitchen->id);
    $dish = makeDish('Jollof Rice', [['ingredient' => $rice, 'needed' => 0.3]]);

    expect($dish->available_stock)->toBe(0);
});

it('computes portions from the limiting recipe ingredient', function () {
    $rice = makeIngredient('Rice', legacyQuantity: 0, kitchenQuantity: 3.0, kitchenWarehouseId: $this->kitchen->id);
    $chicken = makeIngredient('Chicken', legacyQuantity: 0, kitchenQuantity: 1.0, kitchenWarehouseId: $this->kitchen->id);

    // Rice allows 10 portions, chicken only 4 — the smaller one wins.
    $dish = makeDish('Rice and Chicken', [
        ['ingredient' => $rice, 'needed' => 0.3],
        ['ingredient' => $chicken, 'needed' => 0.25],
    ]);

    expect($dish->available_stock)->toBe(4);
});

it('treats an ingredient with no kitchen inventory row as zero, not as unlimited', function () {
    $spice = makeIngredient('Spice', legacyQuantity: 99, kitchenQuantity: null, kitchenWarehouseId: $this->kitchen->id);
    $dish = makeDish('Peppered Something', [['ingredient' => $spice, 'needed' => 0.1]]);

    expect($dish->available_stock)->toBe(0);
});

it('ignores stock sitting at another warehouse', function () {
    $rice = makeIngredient('Rice', legacyQuantity: 0, kitchenQuantity: null, kitchenWarehouseId: $this->kitchen->id);
    IngredientInventoryItem::create([
        'ingredient_id' => $rice->id, 'warehouse_id' => $this->bar->id, 'quantity' => 100,
    ]);

    $dish = makeDish('Jollof Rice', [['ingredient' => $rice, 'needed' => 0.3]]);

    expect($dish->available_stock)->toBe(0);
});

it('still reports a recipe-less item as unlimited', function () {
    $dish = makeDish('Corkage', []);

    expect($dish->available_stock)->toBeNull();
});

/**
 * The safety valve. While enforcement is off the POS will happily sell
 * these anyway, so reporting a real zero would grey out tiles that are
 * not actually blocked — the display has to agree with the gate.
 */
it('reports unlimited while kitchen ingredient enforcement is off, even with zero stock', function () {
    Company::find(1)->update(['enforce_kitchen_ingredient_stock' => false]);

    $rice = makeIngredient('Rice', legacyQuantity: 0, kitchenQuantity: 0, kitchenWarehouseId: $this->kitchen->id);
    $dish = makeDish('Jollof Rice', [['ingredient' => $rice, 'needed' => 0.3]]);

    expect(InventoryService::enforceIngredientStock())->toBeFalse()
        ->and($dish->available_stock)->toBeNull();
});

it('starts reporting real portions the moment enforcement is switched on', function () {
    Company::find(1)->update(['enforce_kitchen_ingredient_stock' => false]);

    $rice = makeIngredient('Rice', legacyQuantity: 0, kitchenQuantity: 3.0, kitchenWarehouseId: $this->kitchen->id);
    $dish = makeDish('Jollof Rice', [['ingredient' => $rice, 'needed' => 0.3]]);

    expect($dish->available_stock)->toBeNull();

    Company::find(1)->update(['enforce_kitchen_ingredient_stock' => true]);

    // once() memoises enforceIngredientStock() per request; a real toggle
    // flip lands on the next request, which is what this simulates.
    Illuminate\Support\Once::flush();

    expect($dish->fresh(['recipes.ingredient.inventory'])->available_stock)->toBe(10);
});

/**
 * The accessor runs once per tile in the POS grid. Without the
 * recipes.ingredient.inventory eager load it lazy-loads per ingredient
 * per tile, which is exactly the N+1 the grid cannot afford.
 */
it('does not query per tile when the inventory relation is eager loaded', function () {
    $rice = makeIngredient('Rice', legacyQuantity: 0, kitchenQuantity: 10.0, kitchenWarehouseId: $this->kitchen->id);

    foreach (range(1, 5) as $i) {
        makeDish("Dish {$i}", [['ingredient' => $rice, 'needed' => 0.3]]);
    }

    $menuItems = MenuItem::with(['recipes.ingredient.inventory'])->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach ($menuItems as $menuItem) {
        $menuItem->available_stock;
    }

    // Only the memoised enforceIngredientStock() lookup and the cached
    // warehouse id may touch the database here — never one per dish.
    expect(count(DB::getQueryLog()))->toBeLessThan(3);

    DB::disableQueryLog();
});
