<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\PinAuthService;
use Spatie\Permission\Models\Role;

function seedBarHandoverForIntegerTest(int $quantity = 24): array
{
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => $quantity]);

    Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($outgoing, '5793');
    $pinAuth->setPin($incoming, '2846');

    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    return compact('bar', 'product', 'outgoing', 'incoming');
}

it('rejects a fractional quantity on a bar_handover count', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverForIntegerTest();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    expect(fn () => $service->recordCount($item, ['Fridge' => 24.5], $outgoing->id))
        ->toThrow(Exception::class, "'Fridge' must be a whole number — bar counts don't take fractions.");
});

it('accepts a whole-number quantity on a bar_handover count', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverForIntegerTest();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    $item = $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    expect((float) $item->counted_quantity)->toEqual(24.0);
});

it('rejects a fractional dispute figure and a fractional amendment on a bar_handover count', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverForIntegerTest();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-int-declare');
    $service->bindIncomingCustodian($session, '2846', 'test-int-bind');
    $item->refresh();

    expect(fn () => $service->reviewProduct($item, $incoming->id, 'disputed', ['Fridge' => 20.25]))
        ->toThrow(Exception::class);

    $service->reviewProduct($item, $incoming->id, 'disputed', ['Fridge' => 20]);

    expect(fn () => $service->amendDeclaration($item, '5793', ['Fridge' => 21.5], 'test-int-amend'))
        ->toThrow(Exception::class);

    $item = $service->amendDeclaration($item, '5793', ['Fridge' => 21], 'test-int-amend-2');
    expect((float) $item->counted_quantity)->toEqual(21.0);
});

it('allows a fractional quantity on a kitchen_handover ingredient count', function () {
    $kitchen = WareHouse::firstOrCreate(['id' => 5], ['name' => 'Kitchen', 'is_active' => 1]);
    Role::firstOrCreate(['name' => 'chef']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('chef');
    $incoming = User::factory()->create();
    $incoming->assignRole('chef');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($outgoing, '5793');
    $pinAuth->setPin($incoming, '2846');

    Shift::create(['user_id' => $outgoing->id, 'type' => 'chef', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $ingredient = \App\Models\Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 10, 'category' => 'Dry Goods']);
    \App\Models\IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $service = new CountSessionService();
    $session = $service->openSession('kitchen_handover', $kitchen->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();

    $item = $service->recordCount($item, ['Shelf A' => 2.5], $outgoing->id);
    expect((float) $item->counted_quantity)->toEqual(2.5);
});

it('formats a bar_handover count as a whole number on back-navigation, not "24.00"', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedBarHandoverForIntegerTest();

    Role::firstOrCreate(['name' => 'bartender']);
    \App\Models\PagePermission::firstOrCreate(
        ['page_class' => \App\Filament\Pages\CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => \App\Filament\Pages\CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);

    $component = \Livewire\Livewire::actingAs($outgoing)
        ->test(\App\Filament\Pages\CountSessionDetail::class, ['session_id' => $session->id]);

    $safeItems = $component->instance()->safeCountItems();
    expect($safeItems[0]['values']['Fridge'])->toBe('24');
    expect($safeItems[0]['values']['Fridge'])->not->toBe('24.00');
});
