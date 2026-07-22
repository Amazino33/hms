<?php

use App\Filament\Pages\SupervisorDashboard;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\ShiftChannelConfirmation;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\CashierSettlementService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('lists a flagged destination POS mismatch and rules it through the real dashboard page', function () {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);

    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'awaiting_cashier']);

    foreach (['bar' => 3000, 'kitchen' => 2000] as $destination => $amount) {
        $order = Order::create([
            'order_number' => 'ORD-'.strtoupper($destination).'-'.uniqid(),
            'shift_id' => $shift->id, 'user_id' => $waiter->id, 'destination' => $destination,
            'status' => 'paid', 'total_amount' => $amount, 'amount_paid' => $amount, 'paid_cash' => $amount,
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'user_id' => $waiter->id, 'shift_id' => $shift->id,
            'amount' => $amount, 'method' => 'cash', 'paid_at' => now(), 'verified' => true,
        ]);
    }

    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::firstOrCreate(['name' => 'manager']));

    $settlement = new CashierSettlementService;
    $settlement->confirmChannelForDestination($shift, 'bar', 'cash', 3000, $supervisor->id);
    $settlement->confirmChannelForDestination($shift->fresh(), 'bar', 'pos', 800, $supervisor->id); // expected 0 — mismatch
    $settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'cash', 2000, $supervisor->id);
    $settlement->confirmChannelForDestination($shift->fresh(), 'kitchen', 'pos', 0, $supervisor->id);

    $flagged = ShiftChannelConfirmation::where('shift_id', $shift->id)->where('flagged', true)->first();
    expect($flagged)->not->toBeNull();

    $component = Livewire::actingAs($supervisor)->test(SupervisorDashboard::class);
    $component->assertSee('Bar POS mismatch');

    $component->call('openChannelRuling', $flagged->id);
    $component->set('rulingNote', 'Confirmed with the bar POS terminal log');
    $component->call('ruleChannel', 'charge');

    expect($flagged->fresh()->ruling)->toBe('charge');
    expect($flagged->fresh()->flagged)->toBeFalse();
    expect($shift->fresh()->status)->toBe('confirmed');
    expect(StaffDebt::where('shift_id', $shift->id)->exists())->toBeTrue();
});
