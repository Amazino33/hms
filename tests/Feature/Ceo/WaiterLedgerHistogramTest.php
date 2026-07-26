<?php

use App\Filament\Ceo\Pages\WaiterLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('renders the best-performing-waiters chart on the CEO waiter ledger all-waiters view', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');
    Role::firstOrCreate(['name' => 'ceo']);
    Role::firstOrCreate(['name' => 'waiter']);

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $waiter = User::factory()->create(['name' => 'Top Waiter']);
    $waiter->assignRole('waiter');

    $shift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(3),
        'ended_at' => now(), 'status' => 'confirmed',
    ]);
    $order = Order::create([
        'order_number' => 'ORD-'.uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'paid', 'total_amount' => 5000, 'amount_paid' => 5000,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 10, 'unit_price' => 500, 'subtotal' => 5000]);
    DB::table('commissions')->insert(['user_id' => $waiter->id, 'order_id' => $order->id, 'amount' => 100, 'created_at' => now()]);

    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('ceo'));

    Livewire::actingAs($ceo)
        ->test(WaiterLedger::class)
        ->set('mode', 'all_waiters')
        ->assertSee('Best Performing Waiters')
        ->assertSee('Top Waiter');
});
