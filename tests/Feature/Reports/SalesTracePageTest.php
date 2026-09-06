<?php

use App\Filament\Pages\SalesTrace;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\PermissionService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->warehouse = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $this->drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
});

function seedSale(User $waiter, Product $product, int $warehouseId, float $billed, float $deducted, string $orderNumber): Order
{
    $order = Order::create([
        'order_number' => $orderNumber, 'user_id' => $waiter->id, 'status' => 'paid',
        'total_amount' => $billed * 1500, 'is_return' => false,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'item_type' => 'product',
        'product_name' => $product->name, 'quantity' => $billed,
        'unit_price' => 1500, 'subtotal' => $billed * 1500,
    ]);

    if ($deducted > 0) {
        InventoryTransaction::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'type' => 'sale',
            'quantity' => $deducted, 'reference' => "order:{$order->id}", 'user_id' => $waiter->id,
        ]);
    }

    return $order;
}

/**
 * Pages are deny-by-default via PagePermission — a new report page must
 * not quietly become visible to every authenticated panel user just
 * because it was added.
 */
it('denies access to a role with no page permission granted', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($user);

    expect(PermissionService::canAccessPage(SalesTrace::class))->toBeFalse();
});

it('allows a role that has been granted the page', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'manager']));

    PagePermission::create([
        'page_class' => SalesTrace::class,
        'page_name' => 'Sales Trace',
        'role_name' => 'manager',
    ]);

    $this->actingAs($user);

    expect(PermissionService::canAccessPage(SalesTrace::class))->toBeTrue();
});

it('is discoverable by the page permissions manager so it can actually be granted', function () {
    expect(array_keys(PermissionService::getAvailablePages()))->toContain(SalesTrace::class);
});

it('renders the per day per item per waiter rows and flags the stock gap', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $waiter = User::factory()->create(['name' => 'Chidi']);
    $product = Product::create([
        'name' => 'Amstel Malt', 'sku' => 'AM-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);

    seedSale($waiter, $product, $this->warehouse->id, billed: 12, deducted: 10, orderNumber: 'ORD-A');

    Livewire::actingAs($admin)
        ->test(SalesTrace::class)
        ->assertOk()
        ->assertSee('Amstel Malt')
        ->assertSee('Chidi');
});

it('computes totals that count the mismatching rows', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $waiter = User::factory()->create(['name' => 'Ngozi']);
    $clean = Product::create([
        'name' => 'Star Lager', 'sku' => 'SL-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);
    $short = Product::create([
        'name' => 'Guinness', 'sku' => 'GN-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);

    seedSale($waiter, $clean, $this->warehouse->id, billed: 4, deducted: 4, orderNumber: 'ORD-B');
    seedSale($waiter, $short, $this->warehouse->id, billed: 6, deducted: 2, orderNumber: 'ORD-C');

    $component = Livewire::actingAs($admin)->test(SalesTrace::class);
    $totals = $component->instance()->totals();

    expect($totals['billed'])->toBe(10.0)
        ->and($totals['mismatches'])->toBe(1);
});

/**
 * The whole reason the session picker exists: a typed calendar range and
 * the window a count actually measures are different things, and the page
 * has to say which one produced the numbers on screen.
 */
it('labels a plain date range as possibly not lining up with a count window', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $window = Livewire::actingAs($admin)->test(SalesTrace::class)->instance()->window();

    expect($window['exact'])->toBeFalse()
        ->and($window['label'])->toContain('may not line up with a count window');
});

it('switches between the detailed rows and the waiter tally', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $waiter = User::factory()->create(['name' => 'Emeka']);
    $product = Product::create([
        'name' => 'Trophy', 'sku' => 'TR-1', 'category_id' => $this->drinks->id,
        'price' => 1500, 'cost_price' => 900,
    ]);
    seedSale($waiter, $product, $this->warehouse->id, billed: 5, deducted: 5, orderNumber: 'ORD-D');

    $component = Livewire::actingAs($admin)
        ->test(SalesTrace::class)
        ->call('setViewMode', 'tally')
        ->assertSet('viewMode', 'tally')
        ->assertSee('Emeka');

    $tally = $component->instance()->tally();

    expect($tally['items']->first()['name'])->toBe('Trophy')
        ->and($tally['items']->first()['total'])->toBe(5.0);
});
