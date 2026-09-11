<?php

use App\Filament\Pages\StorekeeperTransfers;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WareHouse;
use Spatie\Permission\Models\Role;

/**
 * The /stock-transfers routes sit behind plain `auth` and nothing else at
 * the route layer, so every check has to live in the controller. Two of
 * the six had none at all: the warehouse quantity lookups the transfer
 * form uses for its "how much is actually there" hint were readable by any
 * authenticated user — a waiter or receptionist could walk product and
 * warehouse ids and read the whole stock position. Creation also accepted
 * warehouse ids that did not exist, and a warehouse transferring to
 * itself.
 */
function hardeningWorld(): array
{
    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);

    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $main->id, 'quantity' => 100]);

    return compact('main', 'bar', 'beer');
}

function grantStorekeeperPage(string $role): void
{
    PagePermission::firstOrCreate(
        ['page_class' => StorekeeperTransfers::class, 'role_name' => $role],
        ['page_class' => StorekeeperTransfers::class, 'page_name' => 'Stock Transfers', 'role_name' => $role]
    );
}

it('refuses the product quantity lookup to a user with no transfer page grant', function () {
    ['main' => $main, 'beer' => $beer] = hardeningWorld();

    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($waiter)
        ->getJson("/warehouses/{$main->id}/product/{$beer->id}/quantity")
        ->assertForbidden();
});

it('still serves the product quantity lookup to whoever may use the transfer form', function () {
    ['main' => $main, 'beer' => $beer] = hardeningWorld();

    grantStorekeeperPage('storekeeper');
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $this->actingAs($storekeeper)
        ->getJson("/warehouses/{$main->id}/product/{$beer->id}/quantity")
        ->assertOk()
        ->assertJson(['quantity' => 100.0]);
});

it('refuses the ingredient quantity lookup to a user with no transfer page grant', function () {
    ['main' => $main] = hardeningWorld();

    $ingredient = Ingredient::create([
        'name' => 'Rice', 'sku' => 'ING-'.uniqid(), 'unit_name' => 'kg',
        'quantity' => 0, 'cost_per_unit' => 100, 'category' => 'Grains',
    ]);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $main->id, 'quantity' => 20]);

    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));

    $this->actingAs($receptionist)
        ->getJson("/warehouses/{$main->id}/ingredient/{$ingredient->id}/quantity")
        ->assertForbidden();
});

it('rejects a transfer created against a warehouse id that does not exist', function () {
    ['main' => $main, 'beer' => $beer] = hardeningWorld();

    grantStorekeeperPage('storekeeper');
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $this->actingAs($storekeeper)
        ->postJson('/stock-transfers', [
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => 99999,
            'items' => [['product_id' => $beer->id, 'quantity' => 5]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('to_warehouse_id');

    expect(StockTransfer::count())->toBe(0);
});

it('rejects a transfer from a warehouse to itself', function () {
    ['main' => $main, 'beer' => $beer] = hardeningWorld();

    grantStorekeeperPage('storekeeper');
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $this->actingAs($storekeeper)
        ->postJson('/stock-transfers', [
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $main->id,
            'items' => [['product_id' => $beer->id, 'quantity' => 5]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('to_warehouse_id');

    expect(StockTransfer::count())->toBe(0);
});
