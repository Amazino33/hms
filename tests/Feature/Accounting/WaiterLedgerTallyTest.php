<?php

use App\Filament\Pages\WaiterLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function makeTallyOrder(User $waiter, array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ORD-TALLY-' . uniqid(),
        'user_id' => $waiter->id,
        'status' => 'served',
        'destination' => 'bar',
        'total_amount' => 1000,
        'amount_paid' => 0,
    ], $overrides));
}

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

it('groups items into one column per waiter with a correct total', function () {
    $wendy = User::factory()->create(['name' => 'Wendy']);
    $mike = User::factory()->create(['name' => 'Mike']);

    $order1 = makeTallyOrder($wendy);
    OrderItem::create(['order_id' => $order1->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000]);

    $order2 = makeTallyOrder($mike);
    OrderItem::create(['order_id' => $order2->id, 'product_name' => 'Coke', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300]);

    $component = Livewire::actingAs($this->admin)->test(WaiterLedger::class);
    $columns = $component->instance()->tallyColumns;

    expect($columns)->toHaveCount(2);

    $wendyColumn = collect($columns)->firstWhere('waiter.id', $wendy->id);
    expect((float) $wendyColumn['total'])->toBe(1000.0);
    expect($wendyColumn['items'])->toHaveCount(1);
});

it('excludes other destinations when filtered to bar', function () {
    $wendy = User::factory()->create(['name' => 'Wendy']);

    $barOrder = makeTallyOrder($wendy, ['destination' => 'bar']);
    OrderItem::create(['order_id' => $barOrder->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    $kitchenOrder = makeTallyOrder($wendy, ['destination' => 'kitchen']);
    OrderItem::create(['order_id' => $kitchenOrder->id, 'product_name' => 'Rice', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);

    $component = Livewire::actingAs($this->admin)->test(WaiterLedger::class)
        ->set('tallyDestination', 'bar');

    $columns = $component->instance()->tallyColumns;
    expect($columns)->toHaveCount(1);
    expect((float) $columns[0]['total'])->toBe(500.0);
});

it('shows everything when destination is set to All', function () {
    $wendy = User::factory()->create(['name' => 'Wendy']);

    $barOrder = makeTallyOrder($wendy, ['destination' => 'bar']);
    OrderItem::create(['order_id' => $barOrder->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    $kitchenOrder = makeTallyOrder($wendy, ['destination' => 'kitchen']);
    OrderItem::create(['order_id' => $kitchenOrder->id, 'product_name' => 'Rice', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);

    $component = Livewire::actingAs($this->admin)->test(WaiterLedger::class)
        ->set('tallyDestination', '');

    $columns = $component->instance()->tallyColumns;
    expect((float) $columns[0]['total'])->toBe(1500.0);
});

it('excludes cancelled orders and orders from a different day', function () {
    $wendy = User::factory()->create(['name' => 'Wendy']);

    $cancelled = makeTallyOrder($wendy, ['status' => 'cancelled']);
    OrderItem::create(['order_id' => $cancelled->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    $yesterday = makeTallyOrder($wendy, ['created_at' => now()->subDay()]);
    OrderItem::create(['order_id' => $yesterday->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    $component = Livewire::actingAs($this->admin)->test(WaiterLedger::class);
    expect($component->instance()->tallyColumns)->toHaveCount(0);
});

it('toggles between flat and columns view', function () {
    Livewire::actingAs($this->admin)
        ->test(WaiterLedger::class)
        ->assertSet('viewMode', 'flat')
        ->call('setViewMode', 'columns')
        ->assertSet('viewMode', 'columns')
        ->assertSee('Daily Tally');
});
