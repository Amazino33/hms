<?php

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\KitchenWasteLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\KitchenOrderService;
use App\Services\OrderSplitter;
use App\Services\ReturnConfirmationService;
use App\Services\ServedConfirmationService;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

/**
 * Phase 0F — stock integrity. See docs/audits/phase-0F-verification.md.
 */
function sfFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $food = Category::create(['name' => 'Food', 'type' => 'food']);

    $beer = Product::create(['name' => 'Beer', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);

    $jollof = MenuItem::create(['name' => 'Jollof', 'sku' => 'MI-SF-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-SF-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 300, 'category' => 'Grains']);
    Recipe::create(['menu_item_id' => $jollof->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 2]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $waiter = User::factory()->create();
    $waiterShift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    // Like production: order-item ids run far past product ids, so a dish
    // keyed by its order-item id never lines up with a real product.
    $old = Order::create(['order_number' => 'ORD-OLD-'.uniqid(), 'status' => 'cancelled', 'destination' => 'bar', 'total_amount' => 0]);
    foreach (range(1, 5) as $i) {
        OrderItem::create(['order_id' => $old->id, 'product_id' => $beer->id, 'product_name' => 'x', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0]);
    }

    return compact('bar', 'kitchen', 'beer', 'jollof', 'rice', 'waiter', 'waiterShift', 'chef', 'bartender', 'table');
}

function sfRice(): float
{
    return (float) IngredientInventoryItem::value('quantity');
}

function sfBeer(array $f): float
{
    return (float) InventoryItem::where('product_id', $f['beer']->id)->value('quantity');
}

/** 1× Jollof (kitchen, pending) + 2× Beer (bar, deducted at creation). */
function sfOrder(array $f, int $beers = 2, int $jollofs = 1): array
{
    $cart = [];
    if ($jollofs) {
        $cart['menu_'.$f['jollof']->id] = ['name' => 'Jollof', 'price' => 4500, 'quantity' => $jollofs];
    }
    if ($beers) {
        $cart[(string) $f['beer']->id] = ['name' => 'Beer', 'price' => 1000, 'quantity' => $beers];
    }

    $orders = collect((new OrderSplitter)->handle($cart, $f['table']->id, $f['waiter']->id, ['status' => 'pending', 'shift_id' => $f['waiterShift']->id]));

    return [$orders->firstWhere('destination', 'kitchen'), $orders->firstWhere('destination', 'bar')];
}

function sfServe(array $f, ?Order $kitchen, ?Order $bar): void
{
    if ($kitchen) {
        (new KitchenOrderService)->markReady($kitchen->id, $f['chef']->id);
        (new ServedConfirmationService)->confirm($kitchen->fresh(), $f['waiter']);
    }
    if ($bar) {
        $bar->update(['status' => 'ready']);
        (new ServedConfirmationService)->confirm($bar->fresh(), $f['waiter']);
    }
}

function sfPay(array $f, float $amount)
{
    return Livewire::actingAs($f['waiter'])
        ->test('pos')
        ->set('selectedTableId', (string) $f['table']->id)
        ->call('processPayment', [], $amount, 'cash');
}

it('takes nothing at payment time while food is still cooking, and exactly once at Mark Ready', function () {
    $f = sfFixture();
    [$kitchen] = sfOrder($f, beers: 0);

    // The full payment screen refuses a table whose food is still pending.
    sfPay($f, 4500)->assertReturned(false);
    expect(IngredientTransaction::count())->toBe(0);
    expect(sfRice())->toBe(10.0);

    sfServe($f, $kitchen, null);
    expect(IngredientTransaction::where('type', 'usage')->count())->toBe(1);
    expect(sfRice())->toBe(8.0);
});

it('pays a served table of food and drinks with no second deduction — and keeps the dish a dish', function () {
    $f = sfFixture();
    [$kitchen, $bar] = sfOrder($f);
    sfServe($f, $kitchen, $bar);
    expect(sfRice())->toBe(8.0);
    expect(sfBeer($f))->toBe(18.0);

    $pos = Livewire::actingAs($f['waiter'])->test('pos')->set('selectedTableId', (string) $f['table']->id);
    // The two lines no longer collide into one, so the bill is right.
    expect($pos->get('existingTotal'))->toEqual(6500);
    $pos->call('processPayment', [], 6500.0, 'cash')->assertReturned(true);

    // Stock: exactly one deduction each, and no second ledger entry.
    expect(sfRice())->toBe(8.0);
    expect(IngredientTransaction::where('type', 'usage')->sum('quantity'))->toEqual(2);
    expect(sfBeer($f))->toBe(18.0);
    expect(InventoryTransaction::where('type', 'sale')->sum('quantity'))->toEqual(2);
    expect(InventoryTransaction::where('type', 'return')->exists())->toBeFalse();

    // The re-created orders are the right items, carry "already deducted", and are paid.
    $newKitchen = Order::where('status', 'paid')->where('destination', 'kitchen')->sole();
    $newBar = Order::where('status', 'paid')->where('destination', 'bar')->sole();
    expect($newKitchen->items()->sole()->only(['item_type', 'menu_item_id', 'quantity']))->toBe(['item_type' => 'menu_item', 'menu_item_id' => $f['jollof']->id, 'quantity' => 1]);
    expect($newBar->items()->sole()->product_id)->toBe($f['beer']->id);
    expect($newKitchen->stock_deducted_at)->not->toBeNull();
    expect($newBar->stock_deducted_at)->not->toBeNull();
    expect((float) OrderPayment::sum('amount'))->toEqual(6500.0);
});

it('rolls everything back when re-creation fails, so the bill is never lost', function () {
    $f = sfFixture();
    [$kitchen, $bar] = sfOrder($f);
    sfServe($f, $kitchen, $bar);

    // Make re-creation fail partway (the dish has since been deleted).
    Recipe::query()->delete();
    $f['jollof']->delete();

    sfPay($f, 6500)->assertReturned(false);

    expect(Order::whereKey([$kitchen->id, $bar->id])->count())->toBe(2);
    expect($kitchen->fresh()->items()->count())->toBe(1);
    expect(OrderPayment::count())->toBe(0);
    expect(sfBeer($f))->toBe(18.0); // no silent restock left behind
});

it('deducts a takeaway order created already paid exactly once (it never reaches Mark Ready)', function () {
    // The full payment screen can't create takeaway orders (it refuses any
    // unsent cart lines), so this pins the OrderSplitter path that does.
    $f = sfFixture();

    (new OrderSplitter)->handle(['menu_'.$f['jollof']->id => ['name' => 'Jollof', 'price' => 4500, 'quantity' => 1]], null, $f['waiter']->id, ['status' => 'paid', 'amount_paid' => 4500, 'shift_id' => $f['waiterShift']->id]);

    expect(IngredientTransaction::where('type', 'usage')->count())->toBe(1);
    expect(sfRice())->toBe(8.0);
});

it('takes a drink exactly once through the full payment screen', function () {
    $f = sfFixture();
    [, $bar] = sfOrder($f, beers: 3, jollofs: 0);
    sfServe($f, null, $bar);

    sfPay($f, 3000)->assertReturned(true);

    expect(sfBeer($f))->toBe(17.0);
    expect(InventoryTransaction::where('type', 'sale')->sum('quantity'))->toEqual(3);
});

/** Ask for a return from the POS, the way a waiter does, then confirm or reject it. */
function sfReturn(array $f, string $key, string $reason = 'Guest complained'): Order
{
    Livewire::actingAs($f['waiter'])
        ->test('pos')
        ->set('selectedTableId', (string) $f['table']->id)
        ->call('openReturnModal', $key)
        ->set('returnQuantity', 1)
        ->set('returnReason', $reason)
        ->call('submitReturnRequest');

    return Order::where('is_return', true)->latest('id')->first();
}

it('records a returned cooked dish as waste, never restocking it, with the bill adjusted as before', function () {
    $f = sfFixture();
    [$kitchen] = sfOrder($f, beers: 0, jollofs: 2);
    sfServe($f, $kitchen, null);
    expect(sfRice())->toBe(6.0);

    $ticket = sfReturn($f, 'menu_'.$f['jollof']->id, 'Too salty');
    (new ReturnConfirmationService)->confirm($ticket, $f['chef']);

    expect(sfRice())->toBe(6.0);
    expect(IngredientTransaction::where('type', 'return')->exists())->toBeFalse();

    $waste = KitchenWasteLog::sole();
    expect($waste->order_id)->toBe($kitchen->id);
    expect($waste->menu_item_id)->toBe($f['jollof']->id);
    expect($waste->quantity)->toBe(1);
    expect((float) $waste->sale_value)->toBe(4500.0);
    expect($waste->reason)->toBe('Returned: Too salty');
    expect($waste->recorded_by)->toBe($f['chef']->id);

    // Bill: 2 → 1 dish, exactly as the return flow always adjusted it.
    expect((float) $kitchen->fresh()->total_amount)->toBe(4500.0);
    expect($ticket->fresh()->status)->toBe('returned');
});

it('neither restocks nor records waste for a dish returned before it was cooked', function () {
    $f = sfFixture();
    [$kitchen] = sfOrder($f, beers: 0, jollofs: 2);

    $ticket = sfReturn($f, 'menu_'.$f['jollof']->id, 'Ordered by mistake');
    (new ReturnConfirmationService)->confirm($ticket, $f['chef']);

    expect(sfRice())->toBe(10.0);
    expect(IngredientTransaction::count())->toBe(0);
    expect(KitchenWasteLog::count())->toBe(0);
    expect($kitchen->fresh()->items()->sole()->quantity)->toBe(1);

    // Mark Ready later takes only what's left.
    (new KitchenOrderService)->markReady($kitchen->id, $f['chef']->id);
    expect(sfRice())->toBe(8.0);
});

it('still restocks a returned drink, and a rejected return restocks nothing', function () {
    $f = sfFixture();
    [, $bar] = sfOrder($f, beers: 3, jollofs: 0);
    sfServe($f, null, $bar);
    expect(sfBeer($f))->toBe(17.0);

    $confirmed = sfReturn($f, (string) $f['beer']->id, 'Unopened, wrong brand');
    (new ReturnConfirmationService)->confirm($confirmed, $f['bartender']);
    expect(sfBeer($f))->toBe(18.0);
    expect(InventoryTransaction::where('type', 'return')->sum('quantity'))->toEqual(1);

    $rejected = sfReturn($f, (string) $f['beer']->id, 'Says it was flat');
    (new ReturnConfirmationService)->reject($rejected, $f['bartender'], 'Never came back to the bar');
    expect(sfBeer($f))->toBe(18.0);
    expect(InventoryTransaction::where('type', 'return')->sum('quantity'))->toEqual(1);
    expect(KitchenWasteLog::count())->toBe(0);
});

function sfAdmin(): User
{
    test()->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

it('refuses an admin edit that changes the status, moving no stock', function () {
    $f = sfFixture();
    [$kitchen] = sfOrder($f, beers: 0);

    Livewire::actingAs(sfAdmin())
        ->test(EditOrder::class, ['record' => $kitchen->getRouteKey()])
        ->set('data.status', 'ready')
        ->call('save')
        ->assertNotified('Order not changed');

    expect($kitchen->fresh()->status)->toBe('pending');
    expect($kitchen->fresh()->stock_deducted_at)->toBeNull();
    expect(IngredientTransaction::count())->toBe(0);
});

it('refuses an admin edit that changes the order lines or the total', function () {
    $f = sfFixture();
    [, $bar] = sfOrder($f, beers: 2, jollofs: 0);

    $page = Livewire::actingAs(sfAdmin())->test(EditOrder::class, ['record' => $bar->getRouteKey()]);
    $items = $page->get('data.items');
    $first = array_key_first($items);
    $items[$first]['quantity'] = 9;

    $page->set('data.items', $items)->call('save')->assertNotified('Order not changed');
    expect($bar->fresh()->items()->sole()->quantity)->toBe(2);

    $page->set('data.items', $page->get('data.items'))->set('data.total_amount', '1')->call('save');
    expect((float) $bar->fresh()->total_amount)->toBe(2000.0);
});

it('still lets an admin correct the cancellation reason, the one field left editable', function () {
    $f = sfFixture();
    [, $bar] = sfOrder($f, beers: 1, jollofs: 0);
    $bar->update(['status' => 'cancelled', 'cancellation_reason' => 'typo']);

    Livewire::actingAs(sfAdmin())
        ->test(EditOrder::class, ['record' => $bar->getRouteKey()])
        ->set('data.cancellation_reason', 'Guest left before it was served')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($bar->fresh()->cancellation_reason)->toBe('Guest left before it was served');
    expect($bar->fresh()->status)->toBe('cancelled');
});

it('has no Delete button on the admin order edit page', function () {
    $f = sfFixture();
    [, $bar] = sfOrder($f, beers: 1, jollofs: 0);

    Livewire::actingAs(sfAdmin())
        ->test(EditOrder::class, ['record' => $bar->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});
