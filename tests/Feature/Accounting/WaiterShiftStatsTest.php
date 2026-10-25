<?php

use App\Filament\Widgets\WaiterShiftStats;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

function waiterStatsOrder(User $waiter, array $attributes = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ORD-WSS-'.uniqid(),
        'user_id' => $waiter->id,
        'status' => 'served',
        'destination' => 'bar',
        'total_amount' => 1000,
        'amount_paid' => 0,
    ], $attributes));
}

it('leaves cancelled and returned orders out of sales and outstanding debt', function () {
    $waiter = User::factory()->create();

    waiterStatsOrder($waiter, ['total_amount' => 4100, 'amount_paid' => 0]);
    waiterStatsOrder($waiter, ['total_amount' => 2500, 'amount_paid' => 2500, 'status' => 'paid']);
    waiterStatsOrder($waiter, ['total_amount' => 77700, 'amount_paid' => 0, 'status' => 'cancelled']);
    waiterStatsOrder($waiter, ['total_amount' => 88800, 'amount_paid' => 0, 'status' => 'returned']);
    waiterStatsOrder($waiter, ['total_amount' => 99900, 'amount_paid' => 0, 'status' => 'pending', 'is_return' => true]);

    Livewire::actingAs($waiter)
        ->test(WaiterShiftStats::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('₦6,600')   // sales: 4,100 + 2,500
        ->assertSee('₦2,500')   // collected
        ->assertSee('₦4,100')   // debt
        ->assertDontSee('77,700')
        ->assertDontSee('88,800')
        ->assertDontSee('99,900');
});

it('keeps an order from before midnight in the same trading night', function () {
    // 07:00 Lagos on the 25th is still the 24th's business day.
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-25 06:00:00', 'UTC'));
    $waiter = User::factory()->create();

    // 23:00 Lagos on the 24th — a UTC calendar-day filter would drop it.
    $order = waiterStatsOrder($waiter, ['total_amount' => 3300]);
    $order->forceFill(['created_at' => '2026-10-24 22:00:00'])->saveQuietly();

    Livewire::actingAs($waiter)
        ->test(WaiterShiftStats::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('₦3,300');
});
