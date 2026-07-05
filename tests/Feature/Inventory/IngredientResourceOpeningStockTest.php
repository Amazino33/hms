<?php

use App\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\User;
use App\Models\WareHouse;

it('seeds ingredient_inventory_items and logs a purchase transaction from the create-form opening stock', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    // Exercise the page's actual record-creation logic directly (the part
    // that turns the "Opening Stock" form field into a real, transaction-
    // logged stock movement) without needing to bootstrap a full Filament
    // panel/Livewire request cycle.
    $page = new CreateIngredient();
    $reflection = new ReflectionMethod(CreateIngredient::class, 'handleRecordCreation');
    $reflection->setAccessible(true);

    $ingredient = $reflection->invoke($page, [
        'name' => 'Salt',
        'sku' => 'ING-SALT-TEST',
        'unit_name' => 'kg',
        'quantity' => 25,
        'cost_per_unit' => 3,
        'category' => 'Dry Goods',
    ]);

    expect($ingredient)->toBeInstanceOf(Ingredient::class);

    $inventory = IngredientInventoryItem::where('ingredient_id', $ingredient->id)->where('warehouse_id', $mainStore->id)->first();
    expect($inventory)->not->toBeNull();
    expect((float) $inventory->quantity)->toEqual(25.0);

    $txn = IngredientTransaction::where('ingredient_id', $ingredient->id)->where('type', 'purchase')->first();
    expect($txn)->not->toBeNull();
    expect((float) $txn->quantity)->toEqual(25.0);
    expect($txn->reference)->toBe('opening_stock');
});

it('does not write an ingredient_inventory_items row when opening stock is zero', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    $page = new CreateIngredient();
    $reflection = new ReflectionMethod(CreateIngredient::class, 'handleRecordCreation');
    $reflection->setAccessible(true);

    $ingredient = $reflection->invoke($page, [
        'name' => 'Pepper',
        'sku' => 'ING-PEPPER-TEST',
        'unit_name' => 'kg',
        'quantity' => 0,
        'cost_per_unit' => 4,
        'category' => 'Spices',
    ]);

    expect(IngredientInventoryItem::where('ingredient_id', $ingredient->id)->exists())->toBeFalse();
    expect(IngredientTransaction::where('ingredient_id', $ingredient->id)->exists())->toBeFalse();
});
