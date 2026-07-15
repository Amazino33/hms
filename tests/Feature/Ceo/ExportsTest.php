<?php

use App\Filament\Ceo\Pages\LeakageReport;
use App\Filament\Ceo\Pages\OccupancyReport;
use App\Filament\Ceo\Pages\SalesReport;
use App\Filament\Ceo\Pages\WaiterLedger;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    Role::firstOrCreate(['name' => 'ceo']);
});

it('exports the sales report as CSV honoring the active category filter', function () {
    $categoryA = Category::create(['name' => 'Drinks Export', 'type' => 'drink']);
    $categoryB = Category::create(['name' => 'Food Export', 'type' => 'food']);
    $beer = Product::create(['name' => 'Export Beer', 'price' => 500, 'cost_price' => 200, 'category_id' => $categoryA->id, 'is_active' => true]);
    $rice = Product::create(['name' => 'Export Rice', 'price' => 1000, 'cost_price' => 400, 'category_id' => $categoryB->id, 'is_active' => true]);

    $waiter = User::factory()->create();
    $order = Order::create(['order_number' => 'EX-' . uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1500, 'amount_paid' => 1500]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $beer->id, 'product_name' => 'Export Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $rice->id, 'product_name' => 'Export Rice', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    Livewire::actingAs($ceo)->test(SalesReport::class)
        ->set('categoryId', $categoryA->id)
        ->call('exportCsv')
        ->assertFileDownloaded('sales-report.csv');
});

it('exports the sales report as PDF', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    Livewire::actingAs($ceo)->test(SalesReport::class)
        ->call('exportPdf')
        ->assertFileDownloaded('sales-report.pdf');
});

it('exports the waiter ledger as CSV and PDF', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    Livewire::actingAs($ceo)->test(WaiterLedger::class)
        ->set('waiterId', $waiter->id)
        ->call('exportCsv')
        ->assertFileDownloaded('waiter-ledger.csv');

    Livewire::actingAs($ceo)->test(WaiterLedger::class)
        ->set('waiterId', $waiter->id)
        ->call('exportPdf')
        ->assertFileDownloaded('waiter-ledger.pdf');
});

it('exports the occupancy report as CSV and PDF', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    Livewire::actingAs($ceo)->test(OccupancyReport::class)
        ->call('exportCsv')
        ->assertFileDownloaded('occupancy-report.csv');

    Livewire::actingAs($ceo)->test(OccupancyReport::class)
        ->call('exportPdf')
        ->assertFileDownloaded('occupancy-report.pdf');
});

it('exports the leakage report as CSV and PDF', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    Livewire::actingAs($ceo)->test(LeakageReport::class)
        ->call('exportCsv')
        ->assertFileDownloaded('leakage-report.csv');

    Livewire::actingAs($ceo)->test(LeakageReport::class)
        ->call('exportPdf')
        ->assertFileDownloaded('leakage-report.pdf');
});

it('exports the daily digest as a single-page PDF', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    Livewire::actingAs($ceo)->test(\App\Filament\Ceo\Pages\DailyDigest::class)
        ->call('exportPdf')
        ->assertFileDownloaded();
});
