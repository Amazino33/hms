<?php

use App\Filament\Pages\NewProcurement;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * calculateSuggestedPrice() mirrors the price panel's live Alpine preview —
 * see NewProcurement.php's doc comment. Tested directly here since the JS
 * itself can't be executed by Pest.
 */
it('suggests a new price rounded up to the nearest step, preserving the prior margin', function () {
    // last_cost_price 1000, current_selling_price 1500 -> margin ratio 1.5x.
    // new_unit_cost 1200 -> raw suggestion 1800, step 50 -> already a
    // multiple of 50, so it stays 1800.
    $suggested = NewProcurement::calculateSuggestedPrice(
        newUnitCost: 1200,
        currentSellingPrice: 1500,
        lastCostPrice: 1000,
        roundingStep: 50,
    );

    expect($suggested)->toBe(1800.0);
});

it('rounds a suggestion up to the next step when it does not land exactly on one', function () {
    // ratio 1.5x, new_unit_cost 1210 -> raw 1815 -> rounds up to 1850.
    $suggested = NewProcurement::calculateSuggestedPrice(
        newUnitCost: 1210,
        currentSellingPrice: 1500,
        lastCostPrice: 1000,
        roundingStep: 50,
    );

    expect($suggested)->toBe(1850.0);
});

it('returns no suggestion when the product has no last_cost_price on record', function () {
    $suggested = NewProcurement::calculateSuggestedPrice(
        newUnitCost: 1200,
        currentSellingPrice: 1500,
        lastCostPrice: null,
        roundingStep: 50,
    );

    expect($suggested)->toBeNull();
});

it('returns no suggestion when the computed price is within one step of the current price', function () {
    // ratio 1.02x (current 1020 / last cost 1000), new_unit_cost unchanged
    // at 1010 -> raw 1030.2 -> rounds up to 1050, only 30 above the
    // current 1020 — less than the 50 step, so no suggestion, "price OK".
    $suggested = NewProcurement::calculateSuggestedPrice(
        newUnitCost: 1010,
        currentSellingPrice: 1020,
        lastCostPrice: 1000,
        roundingStep: 50,
    );

    expect($suggested)->toBeNull();
});

function grantPriceViaProcurementPermission(\App\Models\User $user, string $roleName): void
{
    $role = Role::firstOrCreate(['name' => $roleName]);
    $user->assignRole($role);
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'update-price-via-procurement', 'guard_name' => 'web']));
}

it('lets a storekeeper apply a price through the procurement flow, logging the change', function () {
    $storekeeper = User::factory()->create();
    grantPriceViaProcurementPermission($storekeeper, 'storekeeper');
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 1500, 'last_cost_price' => 1000, 'is_active' => true,
    ]);

    Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('applyPriceSuggestion', $product->id, 1800);

    expect((float) $product->fresh()->price)->toBe(1800.0);

    $log = \Spatie\Activitylog\Models\Activity::where('log_name', 'product_price')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->properties['old_price'])->toEqual(1500);
    expect($log->properties['new_price'])->toEqual(1800);
    expect($log->causer_id)->toBe($storekeeper->id);
});

it('does not alter an existing order line when the product price is changed afterward', function () {
    $storekeeper = User::factory()->create();
    grantPriceViaProcurementPermission($storekeeper, 'storekeeper');
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 1500, 'last_cost_price' => 1000, 'is_active' => true,
    ]);
    \Illuminate\Support\Facades\DB::table('tables')->insertOrIgnore([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $waiter = User::factory()->create();

    $order = Order::create([
        'order_number' => 'ORD-TEST-' . uniqid(),
        'table_id' => 1, 'user_id' => $waiter->id, 'status' => 'served',
        'total_amount' => 1500, 'destination' => 'bar',
    ]);
    $orderItem = OrderItem::create([
        'order_id' => $order->id, 'item_type' => 'product', 'product_id' => $product->id,
        'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 1500, 'subtotal' => 1500,
    ]);

    Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('applyPriceSuggestion', $product->id, 1800);

    expect((float) $product->fresh()->price)->toBe(1800.0);
    expect((float) $orderItem->fresh()->unit_price)->toBe(1500.0);
    expect((float) $orderItem->fresh()->subtotal)->toBe(1500.0);
});

it('refuses to apply a price change for a storekeeper who lacks the permission', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    // Deliberately NOT granted update-price-via-procurement.
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 1500, 'last_cost_price' => 1000, 'is_active' => true,
    ]);

    Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('applyPriceSuggestion', $product->id, 1800);

    expect((float) $product->fresh()->price)->toBe(1500.0);
});

it('confirms a storekeeper has no general product-edit permission but can still apply via procurement', function () {
    $storekeeper = User::factory()->create();
    grantPriceViaProcurementPermission($storekeeper, 'storekeeper');

    expect($storekeeper->can('Update:Product'))->toBeFalse();
    expect($storekeeper->can('update-price-via-procurement'))->toBeTrue();
});
