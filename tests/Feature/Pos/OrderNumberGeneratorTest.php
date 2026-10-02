<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\Orders\OrderNumberGenerator;
use App\Services\OrderSplitter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Phase 0A — order numbers come from a per-day, per-station counter
 * instead of the current second, so a burst of orders can never collide
 * on orders.order_number's unique index.
 */
function onBarFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 800, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 100]);

    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    $table = TableModel::create(['name' => 'Table ON '.uniqid(), 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    return compact('beer', 'waiter', 'shift', 'table');
}

function onPlaceBeer(array $f): Order
{
    return collect((new OrderSplitter)->handle(
        [(string) $f['beer']->id => ['name' => 'Star Beer', 'price' => 800, 'quantity' => 1]],
        $f['table']->id, $f['waiter']->id, ['status' => 'pending', 'shift_id' => $f['shift']->id],
    ))->sole();
}

it('gives two same-station orders in the same second different numbers, and saves both', function () {
    $f = onBarFixture();
    Carbon::setTestNow('2026-10-01 12:00:00');

    $first = onPlaceBeer($f);
    $second = onPlaceBeer($f);

    expect($first->exists)->toBeTrue();
    expect($second->exists)->toBeTrue();
    expect($first->order_number)->toBe('ORD-20261001-0001-B');
    expect($second->order_number)->toBe('ORD-20261001-0002-B');
    expect(Order::count())->toBe(2);
});

it('numbers ten orders for one station 0001 to 0010', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');

    $numbers = collect(range(1, 10))->map(fn () => OrderNumberGenerator::next('K'))->all();

    expect($numbers)->toBe(collect(range(1, 10))->map(fn ($n) => sprintf('ORD-20261001-%04d-K', $n))->all());
});

it('keeps an independent sequence per station on the same day', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');

    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261001-0001-B');
    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261001-0002-B');
    expect(OrderNumberGenerator::next('K'))->toBe('ORD-20261001-0001-K');
    expect(OrderNumberGenerator::next('M'))->toBe('ORD-20261001-0001-M');
    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261001-0003-B');

    // Return tickets count on their own series — they never take an ORD- number.
    expect(OrderNumberGenerator::nextReturn('B'))->toBe('RET-20261001-0001-B');
    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261001-0004-B');
});

it('restarts the sequence on a new day', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
    OrderNumberGenerator::next('B');
    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261001-0002-B');

    $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'UTC'));
    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261002-0001-B');
});

it('dates the number in Lagos, not UTC: 23:30 UTC is already tomorrow', function () {
    $this->travelTo(Carbon::parse('2026-10-01 23:30:00', 'UTC'));

    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261002-0001-B');
    expect(DB::table('order_number_sequences')->value('date'))->toStartWith('2026-10-02');
});

it('grows past 9999 to five digits rather than truncating', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
    DB::table('order_number_sequences')->insert(['date' => '2026-10-01', 'station' => 'B', 'last_value' => 9999, 'created_at' => now(), 'updated_at' => now()]);

    expect(OrderNumberGenerator::next('B'))->toBe('ORD-20261001-10000-B');
});

it('never touches existing old-format order numbers', function () {
    $f = onBarFixture();
    $legacy = Order::create([
        'order_number' => 'ORD-1790854483-B', 'table_id' => $f['table']->id, 'user_id' => $f['waiter']->id,
        'status' => 'paid', 'destination' => 'bar', 'total_amount' => 800, 'amount_paid' => 800,
    ]);
    $legacyReturn = Order::create([
        'order_number' => 'RET-1790854483', 'table_id' => $f['table']->id, 'user_id' => $f['waiter']->id,
        'status' => 'returned', 'destination' => 'bar', 'total_amount' => 0, 'is_return' => true,
    ]);

    onPlaceBeer($f);
    onPlaceBeer($f);

    expect($legacy->fresh()->order_number)->toBe('ORD-1790854483-B');
    expect($legacyReturn->fresh()->order_number)->toBe('RET-1790854483');
    expect(Order::where('order_number', 'like', 'ORD-%-0001-B')->count())->toBe(1);
});

it('numbers return tickets from the generator too, so two in one second no longer collide', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
    $f = onBarFixture();
    $order = onPlaceBeer($f);
    Order::whereKey($order->id)->update(['status' => 'served']);

    $pos = Livewire::actingAs($f['waiter'])
        ->test('pos')
        ->set('selectedTableId', (string) $f['table']->id);

    foreach ([1, 2] as $attempt) {
        $pos->call('openReturnModal', (string) $f['beer']->id)
            ->set('returnReason', 'Guest sent it back')
            ->set('returnQuantity', 1)
            ->call('submitReturnRequest');
    }

    expect(Order::where('is_return', true)->orderBy('id')->pluck('order_number')->all())
        ->toBe(['RET-20261001-0001-B', 'RET-20261001-0002-B']);
});
