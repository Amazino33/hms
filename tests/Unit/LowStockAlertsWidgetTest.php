<?php

use App\Filament\Widgets\LowStockAlertsWidget;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\WareHouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('limits visible low-stock alerts and toggles to show all', function () {
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    // Create 8 ingredients each used by a visible-for-sale menu item
    for ($i = 1; $i <= 8; $i++) {
        $menu = MenuItem::create([
            'name' => "Menu {$i}",
            'sku' => 'M' . $i,
            'type' => 'food',
            'sale_price' => 100,
            'available_for_sale' => true,
        ]);

        $ingredient = Ingredient::create([
            'name' => "Ingredient {$i}",
            'sku' => 'ING' . $i,
            'unit_name' => 'pcs',
            'quantity' => 5, // legacy column, no longer read by InventoryService
            'cost_per_unit' => 10,
            'category' => 'general',
        ]);

        IngredientInventoryItem::create([
            'ingredient_id' => $ingredient->id,
            'warehouse_id' => $kitchen->id,
            'quantity' => 5, // under the default threshold (10)
        ]);

        Recipe::create([
            'menu_item_id' => $menu->id,
            'ingredient_id' => $ingredient->id,
            'quantity_needed' => 1,
        ]);
    }

    $widget = new LowStockAlertsWidget();

    // Default visible limit is 6
    $visible = $widget->visibleLowStockAlerts();
    expect(count($visible))->toBe(6);

    // Total low-stock count should report 8
    expect($widget->totalLowStockCount())->toBe(8);

    // Toggle expansion and ensure we now see all alerts
    $widget->toggleExpanded();
    $expanded = $widget->visibleLowStockAlerts();
    expect(count($expanded))->toBe(8);
});
