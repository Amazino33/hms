<?php

use App\Filament\Ceo\Pages\Dashboard;
use App\Filament\Ceo\Pages\ReportExplorer;
use App\Models\Category;
use App\Models\DailyBusinessSnapshot;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

beforeEach(function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $this->user = User::factory()->create();
    $this->user->assignRole('ceo');

    // dashboardData() renders tap-through links via ReportExplorer::getUrl(),
    // which needs the 'ceo' panel established as current — normally set by
    // routing to an actual /ceo/* request, which Livewire::test() bypasses.
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('ceo'));
});

it('flags the gap as widening after 2+ consecutive days of increase, and not on a stable or shrinking gap', function () {
    CarbonImmutable::setTestNow('2026-07-20 12:00:00');

    // 16,17,18: strictly increasing gap. 19 (yesterday): live-closed via
    // command below. 20: today, live.
    foreach (['2026-07-16' => 100, '2026-07-17' => 200, '2026-07-18' => 300, '2026-07-19' => 400] as $date => $gap) {
        DailyBusinessSnapshot::create([
            'business_date' => $date, 'gap_total' => $gap, 'gap_unverified_transfers' => $gap,
            'gap_open_folio_balance' => 0, 'gap_unsettled_shift_amount' => 0, 'gap_staff_debt_outstanding' => 0,
            'computed_at' => now(),
        ]);
    }

    $component = Livewire::actingAs($this->user)->test(Dashboard::class);
    $owner = $component->instance()->dashboardData()['owner'];

    expect($owner['gap']['widening'])->toBeTrue();
});

it('does not flag widening when the gap is flat', function () {
    CarbonImmutable::setTestNow('2026-07-20 12:00:00');

    foreach (['2026-07-17' => 100, '2026-07-18' => 100, '2026-07-19' => 100] as $date => $gap) {
        DailyBusinessSnapshot::create([
            'business_date' => $date, 'gap_total' => $gap, 'computed_at' => now(),
        ]);
    }

    $component = Livewire::actingAs($this->user)->test(Dashboard::class);
    $owner = $component->instance()->dashboardData()['owner'];

    expect($owner['gap']['widening'])->toBeFalse();
});

it('shows net position as indicative for a single day and non-indicative for a multi-day range', function () {
    $daily = Livewire::actingAs($this->user)->test(Dashboard::class)->set('preset', 'today');
    expect($daily->instance()->dashboardData()['owner']['net_position']['indicative'])->toBeTrue();

    $weekly = Livewire::actingAs($this->user)->test(Dashboard::class)->set('preset', 'this_week');
    expect($weekly->instance()->dashboardData()['owner']['net_position']['indicative'])->toBeFalse();
});

it('the products tab ranks fast movers by units and by margin as two independently correct orderings', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    // High units, low margin per unit.
    $volumeProduct = Product::create(['name' => 'Cheap Water', 'price' => 100, 'cost_price' => 90, 'category_id' => $category->id, 'is_active' => true]);
    // Low units, high margin per unit.
    $marginProduct = Product::create(['name' => 'Premium Whisky', 'price' => 5000, 'cost_price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $waiter = User::factory()->create();

    $order1 = Order::create(['order_number' => 'V-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 2000, 'amount_paid' => 2000]);
    OrderItem::create(['order_id' => $order1->id, 'product_id' => $volumeProduct->id, 'product_name' => 'Cheap Water', 'item_type' => 'product', 'quantity' => 20, 'unit_price' => 100, 'subtotal' => 2000]);

    $order2 = Order::create(['order_number' => 'M-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 5000, 'amount_paid' => 5000]);
    OrderItem::create(['order_id' => $order2->id, 'product_id' => $marginProduct->id, 'product_name' => 'Premium Whisky', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 5000, 'subtotal' => 5000]);

    $component = Livewire::actingAs($this->user)->test(ReportExplorer::class, ['tab' => 'products']);
    $data = $component->instance()->tabData();

    expect($data['fast_movers_by_units']->first()['item_name'])->toBe('Cheap Water'); // 20 units beats 1
    expect($data['fast_movers_by_margin']->first()['item_name'])->toBe('Premium Whisky'); // 4500 margin beats 200
});

it('marks a sales-tab row cost as estimated when unit_cost_at_sale is missing, and not when present', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 150, 'category_id' => $category->id, 'is_active' => true]);
    $waiter = User::factory()->create();

    $order = Order::create(['order_number' => 'E-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 500, 'amount_paid' => 500]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);
    // No matching InventoryTransaction — pre-Prompt-1-style history.

    $component = Livewire::actingAs($this->user)->test(ReportExplorer::class, ['tab' => 'sales']);
    $row = $component->instance()->tabData()['rows']->firstWhere('item_name', 'Beer');

    expect($row['cost_estimated'])->toBeTrue();
});

it('excludes voided expenses from the total but still lists them', function () {
    $category = ExpenseCategory::create(['name' => 'Utilities', 'is_active' => true]);
    $user = User::factory()->create();

    Expense::create(['amount' => 1000, 'expense_category_id' => $category->id, 'date_incurred' => now()->toDateString(), 'entered_by' => $user->id]);
    Expense::create(['amount' => 500, 'expense_category_id' => $category->id, 'date_incurred' => now()->toDateString(), 'entered_by' => $user->id, 'voided_at' => now(), 'voided_by' => $user->id]);

    $component = Livewire::actingAs($this->user)->test(ReportExplorer::class, ['tab' => 'expenses']);
    $data = $component->instance()->tabData();

    expect($data['total'])->toBe(1000.0);
    expect($data['rows'])->toHaveCount(2); // both still visible
});

it('blocks a non-ceo, non-super-admin role from the report explorer route', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)->get('/ceo/report-explorer')->assertForbidden();
});

it('only super-admin and ceo roles can reach the report explorer, matching the whole panel gate', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get('/ceo/report-explorer')->assertSuccessful();
    $this->actingAs($this->user)->get('/ceo/report-explorer')->assertSuccessful();
});
