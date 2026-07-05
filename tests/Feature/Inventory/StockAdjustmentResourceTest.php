<?php

use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WareHouse;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('creates a pending stock adjustment request through the Filament form without touching stock', function () {
    $this->seed(ShieldSeeder::class);
    $requester = User::factory()->create();
    $requester->assignRole('bartender');
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    Livewire::actingAs($requester)
        ->test(CreateStockAdjustment::class)
        ->fillForm([
            'item_type' => 'product',
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_change' => -3,
            'reason' => 'damage',
            'notes' => 'Bottle broke in transit',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $adjustment = StockAdjustment::first();
    expect($adjustment)->not->toBeNull();
    expect($adjustment->status)->toBe('pending');
    expect($adjustment->requested_by)->toBe($requester->id);
    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
});

it('hides the approve/reject actions from the requester on their own request', function () {
    $this->seed(ShieldSeeder::class);
    $requester = User::factory()->create();
    $requester->assignRole('bartender');
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $adjustment = StockAdjustment::create([
        'item_type' => 'product',
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -3,
        'reason' => 'damage',
        'status' => 'pending',
        'requested_by' => $requester->id,
    ]);

    Livewire::actingAs($requester)
        ->test(ListStockAdjustments::class)
        ->assertTableActionHidden('approve', $adjustment)
        ->assertTableActionHidden('reject', $adjustment);
});

it('allows a different user to approve the request, mutating stock and logging a transaction', function () {
    $this->seed(ShieldSeeder::class);
    $requester = User::factory()->create();
    $requester->assignRole('bartender');
    $approver = User::factory()->create();
    $approver->assignRole('manager');
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $adjustment = StockAdjustment::create([
        'item_type' => 'product',
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -3,
        'reason' => 'damage',
        'status' => 'pending',
        'requested_by' => $requester->id,
    ]);

    Livewire::actingAs($approver)
        ->test(ListStockAdjustments::class)
        ->assertTableActionVisible('approve', $adjustment)
        ->callTableAction('approve', $adjustment);

    expect($adjustment->fresh()->status)->toBe('approved');
    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(17);
    expect(InventoryTransaction::where('product_id', $product->id)->where('type', 'adjustment')->count())->toBe(1);
});
