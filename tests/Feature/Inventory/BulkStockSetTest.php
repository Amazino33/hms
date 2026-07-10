<?php

use App\Filament\Pages\BulkStockSet;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;

it('previews matched, unmatched, and zeroed-out rows without writing anything', function () {
    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $heineken = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $heineken->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);

    $gulder = Product::create(['name' => 'Gulder', 'price' => 400, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $gulder->id, 'warehouse_id' => $bar->id, 'quantity' => 8]);

    $component = Livewire::actingAs($admin)
        ->test(BulkStockSet::class)
        ->set('warehouseId', $bar->id)
        ->set('pasteData', "Heineken, 18\nSome Missing Product, 3")
        ->call('preview');

    expect($component->get('matched'))->toHaveCount(1);
    expect($component->get('matched')[0]['name'])->toBe('Heineken');
    expect($component->get('matched')[0]['old_qty'])->toEqual(5.0);
    expect($component->get('matched')[0]['new_qty'])->toEqual(18.0);

    expect($component->get('unmatched'))->toHaveCount(1);
    expect($component->get('unmatched')[0])->toContain('Some Missing Product');

    expect($component->get('zeroedOut'))->toHaveCount(1);
    expect($component->get('zeroedOut')[0]['name'])->toBe('Gulder');

    // Nothing written yet
    expect(InventoryItem::where('product_id', $heineken->id)->value('quantity'))->toEqual(5.0);
    expect(InventoryItem::where('product_id', $gulder->id)->value('quantity'))->toEqual(8.0);
});

it('applies the previewed changes: sets matched quantities and zeroes out the rest, with an audit trail', function () {
    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $heineken = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $heineken->id, 'warehouse_id' => $bar->id, 'quantity' => 5]);

    $gulder = Product::create(['name' => 'Gulder', 'price' => 400, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $gulder->id, 'warehouse_id' => $bar->id, 'quantity' => 8]);

    Livewire::actingAs($admin)
        ->test(BulkStockSet::class)
        ->set('warehouseId', $bar->id)
        ->set('pasteData', "Heineken, 18")
        ->call('preview')
        ->call('apply');

    expect(InventoryItem::where('product_id', $heineken->id)->value('quantity'))->toEqual(18.0);
    expect(InventoryItem::where('product_id', $gulder->id)->value('quantity'))->toEqual(0.0);

    expect(InventoryTransaction::where('product_id', $heineken->id)->where('type', 'adjustment')->count())->toBe(1);
    expect(InventoryTransaction::where('product_id', $heineken->id)->first()->quantity)->toEqual(13.0);

    expect(InventoryTransaction::where('product_id', $gulder->id)->where('type', 'adjustment')->count())->toBe(1);
    expect(InventoryTransaction::where('product_id', $gulder->id)->first()->quantity)->toEqual(8.0);
});

it('matches product names case-insensitively and ignoring extra whitespace', function () {
    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Big Ben Small', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $component = Livewire::actingAs($admin)
        ->test(BulkStockSet::class)
        ->set('warehouseId', $bar->id)
        ->set('pasteData', "  big   ben small  , 1")
        ->call('preview');

    expect($component->get('matched'))->toHaveCount(1);
    expect($component->get('unmatched'))->toHaveCount(0);
});
