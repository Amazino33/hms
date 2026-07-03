<?php

use App\Filament\Pages\MyShiftReport;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('renders my tab with an outstanding order and an open debt', function () {
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $shift = Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);
    Order::create([
        'order_number' => 'ORD-MYTAB-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'served', 'total_amount' => 1000, 'amount_paid' => 0,
    ]);
    StaffDebt::create([
        'user_id' => $waiter->id, 'amount' => 500, 'reason' => 'shift_shortfall',
        'status' => 'open', 'created_by' => $waiter->id,
    ]);

    $this->actingAs($waiter)
        ->get('/admin/my-shift-report')
        ->assertStatus(200)
        ->assertSee('Unpaid Orders')
        ->assertSee('My Open Debts');
});

it('lets a supervisor convert an outstanding order to a debt from my tab', function () {
    $waiter = User::factory()->create();
    $manager = User::factory()->create();
    Role::firstOrCreate(['name' => 'manager']);
    $manager->assignRole('manager');

    $shift = Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);
    $order = Order::create([
        'order_number' => 'ORD-CONVERT-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'served', 'total_amount' => 750, 'amount_paid' => 0,
    ]);

    auth()->login($manager);
    (new MyShiftReport())->convertToDebt($order->id);

    expect($order->fresh()->status)->toBe('paid');
    expect(StaffDebt::where('order_id', $order->id)->exists())->toBeTrue();
});

it('refuses a plain waiter attempting to convert an order to debt', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);
    $order = Order::create([
        'order_number' => 'ORD-DENY-' . uniqid(),
        'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'served', 'total_amount' => 750, 'amount_paid' => 0,
    ]);

    auth()->login($waiter);
    (new MyShiftReport())->convertToDebt($order->id);

    expect($order->fresh()->status)->toBe('served');
    expect(StaffDebt::count())->toBe(0);
});
