<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\ErrorLogRecorder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * checkout()/processPayment()'s catch blocks used to only recognize a
 * couple of hardcoded substrings ('Out of Stock', 'Insufficient
 * ingredients', 'shift', 'session') and hid every other domain-validation
 * exception (e.g. "Product not found: X") behind a vague "Could not send
 * order"/"Could not process payment" — the real reason was never shown to
 * the person who needed to see it. These lock in that every deliberate
 * \Exception thrown by OrderSplitter/InventoryService now surfaces its
 * actual message, regardless of wording, via ErrorLogRecorder (the same
 * sink SystemErrorLogTest asserts against, since that's what a
 * Notification::make()->danger()->send() call is recorded as).
 */
beforeEach(function () {
    ErrorLogRecorder::clear();
});

afterEach(function () {
    ErrorLogRecorder::clear();
});

function seedOrderErrorMessageFixtures(): array
{
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Origin Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \App\Models\WareHouse::create(['id' => 4, 'name' => 'Bar', 'location' => 'Back', 'is_active' => 1]);
    \App\Models\InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 1]);

    return compact('beer');
}

it('shows the exact out-of-stock reason, not a generic failure message, when checkout() oversells', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedOrderErrorMessageFixtures();

    $cart = [$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 3]];

    $result = Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('checkout', $cart);

    $result->assertReturned(false);

    $entries = collect(ErrorLogRecorder::recent());
    $stockEntry = $entries->first(fn ($e) => str_contains($e['body'] ?? '', 'Out of Stock'));

    expect($stockEntry)->not->toBeNull();
    expect($stockEntry['body'])->toBe('Out of Stock: Only 1 left of Origin Beer');
});

it('shows the exact "product not found" reason instead of a generic failure message', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A deleted/nonexistent product id — this exact case fell through the
    // old hardcoded substring checks straight to "Could not send order".
    $cart = [999999 => ['name' => 'Ghost Product', 'price' => 500, 'quantity' => 1]];

    $result = Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('checkout', $cart);

    $result->assertReturned(false);

    $entries = collect(ErrorLogRecorder::recent());
    $notFoundEntry = $entries->first(fn ($e) => str_contains($e['body'] ?? '', 'Product not found'));

    expect($notFoundEntry)->not->toBeNull();
    expect($notFoundEntry['body'])->toBe('Product not found: Ghost Product');
    expect($entries->contains(fn ($e) => ($e['body'] ?? '') === 'Could not send order'))->toBeFalse();
});

it('shows the exact out-of-stock reason for a direct payment via processPayment(), not a generic message', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedOrderErrorMessageFixtures();

    $cart = [$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 3]];

    $result = Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->set('existingItems', $cart)
        ->call('processPayment', [], 1500.0, 'cash');

    $result->assertReturned(false);

    $entries = collect(ErrorLogRecorder::recent());
    $stockEntry = $entries->first(fn ($e) => str_contains($e['body'] ?? '', 'Out of Stock'));

    expect($stockEntry)->not->toBeNull();
    expect($stockEntry['body'])->toBe('Out of Stock: Only 1 left of Origin Beer');
    expect($entries->contains(fn ($e) => ($e['body'] ?? '') === 'Could not process payment'))->toBeFalse();
});
