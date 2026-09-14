<?php

use App\Models\Commission;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\ShieldSeeder;

/**
 * /admin/users/{id}/edit returned a blank 500 for some staff and rendered
 * fine for others, which read like an intermittent server fault but was
 * data-shaped: the Commission History panel was a Repeater bound with
 * ->relationship(), so it built a full form component tree for every
 * commission the person had ever earned — about 0.8 MB per row. A
 * receptionist has none and loaded instantly; a waiter past roughly 650 paid
 * orders exhausted PHP's 512M limit and died before rendering anything.
 *
 * The panel is display-only, so it now renders the 10 most recent rows as
 * plain markup at a cost that does not grow with history.
 */
function waiterWithCommissions(int $count, string $prefix = 'A'): User
{
    $waiter = User::factory()->create();

    $orders = Order::factory()
        ->count($count)
        ->sequence(fn ($seq) => ['order_number' => "ORD-{$prefix}-".$seq->index])
        ->create();

    // Staggered so "most recent" is well defined — all-identical timestamps
    // would make the ordering arbitrary and the assertions meaningless.
    Commission::insert($orders->values()->map(fn ($order, $i) => [
        'user_id' => $waiter->id,
        'order_id' => $order->id,
        'amount' => 250.00,
        'created_at' => now()->subMinutes($count - $i),
    ])->all());

    return $waiter;
}

beforeEach(function () {
    $this->seed(ShieldSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

it('renders the edit page for a waiter with a long commission history', function () {
    $waiter = waiterWithCommissions(800);

    $this->actingAs($this->admin)
        ->get("/admin/users/{$waiter->id}/edit")
        ->assertStatus(200);
});

/**
 * The guard that matters: cost must not scale with the number of rows, or
 * the 500 simply comes back once a waiter serves enough orders.
 */
it('does not grow memory with the size of the commission history', function () {
    $light = waiterWithCommissions(20, 'LIGHT');
    $heavy = waiterWithCommissions(800, 'HEAVY');

    $before = memory_get_usage(true);
    $this->actingAs($this->admin)->get("/admin/users/{$light->id}/edit")->assertOk();
    $lightCost = memory_get_usage(true) - $before;

    $before = memory_get_usage(true);
    $this->actingAs($this->admin)->get("/admin/users/{$heavy->id}/edit")->assertOk();
    $heavyCost = memory_get_usage(true) - $before;

    // 40x the rows must not mean meaningfully more memory. The old Repeater
    // cost ~0.8 MB per row, so this margin fails loudly if it ever returns.
    expect($heavyCost - $lightCost)->toBeLessThan(32 * 1048576);
});

it('shows only the ten most recent commissions', function () {
    $waiter = waiterWithCommissions(50, 'TEN');

    $response = $this->actingAs($this->admin)->get("/admin/users/{$waiter->id}/edit");

    $response->assertOk();
    // Index 49 is the newest of 50 and must show; 0 is the oldest and must not.
    $response->assertSee('ORD-TEN-49');
    $response->assertSee('ORD-TEN-40');
    $response->assertDontSee('ORD-TEN-39');
});

it('renders the panel for a staff member with no commissions at all', function () {
    $receptionist = User::factory()->create();

    $this->actingAs($this->admin)
        ->get("/admin/users/{$receptionist->id}/edit")
        ->assertOk()
        ->assertSee('No commissions yet.');
});
