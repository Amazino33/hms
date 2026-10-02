<?php

use App\Models\BarShiftConflictLog;
use App\Models\BarShiftWaitLog;
use App\Models\Category;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestSessionHandover;
use App\Models\GuestTableSession;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\GuestSessionHandoverService;
use App\Services\KitchenOrderService;
use App\Services\OrderSplitter;
use App\Services\PinAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 3 — waiter acceptance, the bar queue and release, alerts.
 */
function arUser(string $name, ?string $role = null, ?string $pin = null): User
{
    $user = User::factory()->create(['name' => $name]);

    if ($role) {
        $user->assignRole(Role::firstOrCreate(['name' => $role]));
    }

    if ($pin) {
        (new PinAuthService)->setPin($user, $pin);
    }

    return $user;
}

function arShift(User $user, string $type, ?Carbon $startedAt = null): Shift
{
    return Shift::create(['user_id' => $user->id, 'type' => $type, 'started_at' => $startedAt ?? now(), 'status' => 'active']);
}

function arFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Beers', 'type' => 'drink']);
    $food = Category::create(['name' => 'Rice', 'type' => 'food']);
    DB::table('category_chip_group')->insert([
        ['category_id' => $drinks->id, 'chip_group_id' => DB::table('chip_groups')->where('name', 'Temperature')->value('id')],
        ['category_id' => $food->id, 'chip_group_id' => DB::table('chip_groups')->where('name', 'Food extras')->value('id')],
    ]);

    $beer = Product::create(['name' => 'Star Beer', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);

    $jollof = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-AR-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-AR-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 300, 'category' => 'Grains']);
    Recipe::create(['menu_item_id' => $jollof->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 2]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $emeka = arUser('Emeka Obi', 'waiter', '4826');
    $emekaShift = arShift($emeka, 'waiter');
    $tunde = arUser('Tunde Bello', 'waiter', '5937');
    $tundeShift = arShift($tunde, 'waiter');
    $chef = arUser('Chef Ada', 'chef');
    arShift($chef, 'chef');
    $bartender = arUser('Bisi Bar', 'bartender');
    $bartenderShift = arShift($bartender, 'bartender');

    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    Cache::flush();

    return compact('bar', 'kitchen', 'beer', 'jollof', 'rice', 'emeka', 'emekaShift', 'tunde', 'tundeShift', 'chef', 'bartender', 'bartenderShift', 'table');
}

/** 2× Star Beer (Cold) + 1× Jollof (Extra pepper, note). */
function arSubmit(array $f, string $device = 'a', ?array $lines = null): GuestRequest
{
    return (new GuestRequestService)->submit($f['table']->qr_token, str_repeat($device, 32), $lines ?? [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold']],
        ['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1, 'chips' => ['Extra pepper'], 'note' => 'No crayfish'],
    ]);
}

function arBeer(array $f): float
{
    return (float) InventoryItem::where('product_id', $f['beer']->id)->value('quantity');
}

it('accepts a request: the table becomes the waiter\'s, food is ONE pending kitchen order at the guest\'s price, drinks wait at the bar', function () {
    $f = arFixture();
    $request = arSubmit($f);
    $f['jollof']->update(['sale_price' => 9999]); // a price change after submit

    (new GuestRequestService)->confirm($request, $f['emeka']);

    $session = GuestTableSession::sole();
    expect($session->assigned_waiter_user_id)->toBe($f['emeka']->id);
    expect($session->assigned_shift_id)->toBe($f['emekaShift']->id);
    expect($request->fresh()->status)->toBe('confirmed');
    expect($request->fresh()->confirmed_by_user_id)->toBe($f['emeka']->id);

    $kitchenOrder = Order::sole();
    expect($kitchenOrder->destination)->toBe('kitchen');
    expect($kitchenOrder->status)->toBe('pending');
    expect($kitchenOrder->user_id)->toBe($f['emeka']->id);
    expect($kitchenOrder->shift_id)->toBe($f['emekaShift']->id);
    expect($kitchenOrder->table_id)->toBe($f['table']->id);

    $orderLine = OrderItem::where('order_id', $kitchenOrder->id)->sole();
    expect((float) $orderLine->unit_price)->toBe(4500.0); // D18
    expect($orderLine->chips)->toBe(['Extra pepper']);
    expect($orderLine->note)->toBe('No crayfish');

    $food = GuestRequestItem::where('station', 'kitchen')->sole();
    expect($food->status)->toBe('ordered');
    expect($food->order_id)->toBe($kitchenOrder->id);
    expect($food->order_item_id)->toBe($orderLine->id);

    // No kitchen stock until Mark Ready; no bar order at all yet.
    expect(IngredientTransaction::count())->toBe(0);
    expect(GuestRequestItem::where('station', 'bar')->sole()->status)->toBe('at_bar');
    expect(arBeer($f))->toBe(20.0);

    (new KitchenOrderService)->markReady($kitchenOrder->id, $f['chef']->id);
    expect(IngredientTransaction::where('type', 'usage')->sum('quantity'))->toEqual(2);
});

it('refuses to accept without an active waiter shift, changing nothing', function () {
    $f = arFixture();
    $request = arSubmit($f);
    $offShift = arUser('Off Shift', 'waiter');

    expect(fn () => (new GuestRequestService)->confirm($request, $offShift))->toThrow(Exception::class, 'Start your waiter shift');
    expect($request->fresh()->status)->toBe('pending');
    expect(Order::count())->toBe(0);
    expect(GuestTableSession::sole()->assigned_waiter_user_id)->toBeNull();
});

it('keeps a table with its waiter while they are on shift, then opens it to everyone (D15)', function () {
    $f = arFixture();
    (new GuestRequestService)->confirm(arSubmit($f, 'a'), $f['emeka']);
    $second = arSubmit($f, 'b', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]]);

    expect(fn () => (new GuestRequestService)->confirm($second, $f['tunde']))->toThrow(Exception::class, "This is Emeka's table");

    $f['emekaShift']->update(['ended_at' => now(), 'status' => 'awaiting_cashier']);
    (new GuestRequestService)->confirm($second, $f['tunde']);

    expect(GuestTableSession::sole()->assigned_waiter_user_id)->toBe($f['tunde']->id);
});

it('lets only one of two waiters accept the same request', function () {
    $f = arFixture();
    $request = arSubmit($f);

    (new GuestRequestService)->confirm($request, $f['emeka']);
    expect(fn () => (new GuestRequestService)->confirm($request, $f['tunde']))->toThrow(Exception::class, 'already been handled');

    expect(Order::count())->toBe(1);
    expect(GuestTableSession::sole()->assigned_waiter_user_id)->toBe($f['emeka']->id);
});

it('releases drinks into a bar order under the WAITER\'s shift, at the guest\'s price, crediting the bartender', function () {
    $f = arFixture();
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);
    $f['beer']->update(['price' => 2500]);

    expect((new GuestBarReleaseService)->markReady($request))->toBe('ready');

    $barOrder = Order::where('destination', 'bar')->sole();
    expect($barOrder->status)->toBe('ready'); // D33: one tap
    expect($barOrder->user_id)->toBe($f['emeka']->id);
    expect($barOrder->shift_id)->toBe($f['emekaShift']->id); // D2
    // Set by the Mark Ready step, exactly as the old second tap did.
    expect($barOrder->processed_by_user_id)->toBe($f['bartender']->id);
    $line = OrderItem::where('order_id', $barOrder->id)->sole();
    expect((float) $line->unit_price)->toBe(1000.0); // D18
    expect($line->chips)->toBe(['Cold']);

    expect(arBeer($f))->toBe(18.0);
    expect(InventoryTransaction::where('type', 'sale')->sum('quantity'))->toEqual(2);

    $drinkLine = GuestRequestItem::where('station', 'bar')->sole();
    expect($drinkLine->status)->toBe('released');
    expect($drinkLine->released_by_user_id)->toBe($f['bartender']->id);
    expect($drinkLine->released_at)->not->toBeNull();
    expect($drinkLine->order_item_id)->toBe($line->id);
});

it('refuses to release with no bartender shift open', function () {
    $f = arFixture();
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);
    $f['bartenderShift']->update(['ended_at' => now()]);

    expect(fn () => (new GuestBarReleaseService)->markReady($request))->toThrow(Exception::class, 'Start your bartender shift');
    expect(Order::where('destination', 'bar')->count())->toBe(0);
    expect(GuestRequestItem::where('station', 'bar')->sole()->status)->toBe('at_bar');
});

it('asks who is releasing when two bartender shifts are open (D1)', function () {
    $f = arFixture();
    $second = arUser('Kemi Bar', 'bartender');
    arShift($second, 'bartender');
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    expect(fn () => (new GuestBarReleaseService)->markReady($request))->toThrow(Exception::class, 'choose who is marking this ready');
    expect(fn () => (new GuestBarReleaseService)->markReady($request, $f['tunde']))->toThrow(Exception::class, "isn't on an open bartender shift");
    expect(Order::where('destination', 'bar')->count())->toBe(0);

    (new GuestBarReleaseService)->markReady($request, $second);
    expect(GuestRequestItem::where('station', 'bar')->sole()->released_by_user_id)->toBe($second->id);
});

it('ignores a bartender shift older than the stale limit', function () {
    $f = arFixture();
    $f['bartenderShift']->update(['started_at' => now()->subHours(Shift::STALE_AFTER_HOURS + 1)]);
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    expect(fn () => (new GuestBarReleaseService)->markReady($request))->toThrow(Exception::class, 'Start your bartender shift');
});

it('hands drinks back to the waiters when the table\'s waiter has gone off shift (D3), and another waiter can pick them up', function () {
    $f = arFixture();
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);
    $f['emekaShift']->update(['ended_at' => now(), 'status' => 'awaiting_cashier']);

    expect((new GuestBarReleaseService)->markReady($request))->toBe('returned_to_waiters');
    expect(GuestRequestItem::where('station', 'bar')->sole()->status)->toBe('needs_waiter');
    expect(Order::where('destination', 'bar')->count())->toBe(0);
    expect(GuestTableSession::sole()->assigned_waiter_user_id)->toBeNull();

    (new GuestRequestService)->reacceptReturned($request, $f['tunde']);
    expect(GuestRequestItem::where('station', 'bar')->sole()->status)->toBe('at_bar');

    (new GuestBarReleaseService)->markReady($request);
    expect(Order::where('destination', 'bar')->sole()->user_id)->toBe($f['tunde']->id);
});

it('reduces a drink line with a reason, and refuses impossible reductions', function () {
    $f = arFixture();
    $request = arSubmit($f, 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 3]]);
    (new GuestRequestService)->confirm($request, $f['emeka']);
    $line = GuestRequestItem::sole();
    $service = new GuestBarReleaseService;

    expect(fn () => $service->reduce($line, 0, 'Out of stock'))->toThrow(Exception::class, 'between 1 and 2');
    expect(fn () => $service->reduce($line, 3, 'Out of stock'))->toThrow(Exception::class, 'between 1 and 2');
    expect(fn () => $service->reduce($line, 1, 'Other'))->toThrow(Exception::class, 'Say what the reason is');
    expect(fn () => $service->reduce($line, 1, 'Because'))->toThrow(Exception::class, 'Pick a reason');

    $service->reduce($line, 1, 'Out of stock');
    expect($line->fresh()->quantity_final)->toBe(1);

    $service->markReady($request);
    expect(OrderItem::where('order_id', Order::where('destination', 'bar')->value('id'))->value('quantity'))->toBe(1);
    expect(arBeer($f))->toBe(19.0);
});

it('cancels the request when the bar removes every line, creating no order', function () {
    $f = arFixture();
    $request = arSubmit($f, 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2]]);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    (new GuestBarReleaseService)->remove(GuestRequestItem::sole(), 'Out of stock');

    expect(GuestRequestItem::sole()->status)->toBe('removed');
    expect($request->fresh()->status)->toBe('cancelled_by_staff');
    expect(Order::count())->toBe(0);
});

it('releases once even when Release is tapped twice', function () {
    $f = arFixture();
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    (new GuestBarReleaseService)->markReady($request);
    expect(fn () => (new GuestBarReleaseService)->markReady($request))->toThrow(Exception::class, 'already been marked ready');

    expect(Order::where('destination', 'bar')->count())->toBe(1);
    expect(arBeer($f))->toBe(18.0);
});

it('lets the table\'s waiter cancel drinks still at the bar for free, but never food already ordered', function () {
    $f = arFixture();
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    expect(fn () => (new GuestRequestService)->cancelByStaff($request, $f['tunde'], 'Guest left'))->toThrow(Exception::class, 'Only the table\'s waiter or a manager');

    (new GuestRequestService)->cancelByStaff($request, $f['emeka'], 'Guest changed mind');

    expect(GuestRequestItem::where('station', 'bar')->sole()->status)->toBe('cancelled');
    expect(GuestRequestItem::where('station', 'kitchen')->sole()->status)->toBe('ordered');
    expect($request->fresh()->status)->toBe('confirmed'); // the food is still a live order
    expect(Order::where('destination', 'bar')->count())->toBe(0);
    expect(arBeer($f))->toBe(20.0);

    expect(fn () => (new GuestRequestService)->cancelByStaff($request, $f['emeka'], 'Again'))->toThrow(Exception::class, 'normal void');
});

it('blocks ending a shift with drinks at the bar until the table is handed over', function () {
    $f = arFixture();
    (new GuestRequestService)->confirm(arSubmit($f), $f['emeka']);
    // Food is a pending order — clear it so only the guest block remains.
    Order::where('destination', 'kitchen')->update(['status' => 'paid', 'amount_paid' => DB::raw('total_amount')]);

    expect(fn () => $f['emeka']->endShift())->toThrow(Exception::class, 'Guest drinks are still waiting at the bar for Table 5');

    (new GuestSessionHandoverService)->handover(GuestTableSession::sole(), $f['emeka'], $f['tunde']);

    $handover = GuestSessionHandover::sole();
    expect($handover->from_user_id)->toBe($f['emeka']->id);
    expect($handover->to_user_id)->toBe($f['tunde']->id);
    expect($handover->request_ids)->toBe([GuestRequest::sole()->id]);
    expect(GuestTableSession::sole()->assigned_waiter_user_id)->toBe($f['tunde']->id);

    expect($f['emeka']->endShift()?->ended_at)->not->toBeNull();

    (new GuestBarReleaseService)->markReady(GuestRequest::sole());
    expect(Order::where('destination', 'bar')->sole()->user_id)->toBe($f['tunde']->id);
});

it('leaves guest lines at the bar across a bartender change, for the next bartender to release', function () {
    $f = arFixture();
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    // The handover seal never touches guest lines (phase-3-verification §6);
    // the outgoing shift closes and the incoming one opens.
    foreach (['app/Services/CountSessionService.php', 'app/Services/BartenderChefShiftService.php'] as $path) {
        expect(file_get_contents(base_path($path)))->not->toContain('guest_request')->not->toContain('GuestRequest');
    }
    $f['bartenderShift']->update(['ended_at' => now(), 'status' => 'closed']);
    $next = arUser('Night Bar', 'bartender');
    arShift($next, 'bartender');

    expect(GuestRequestItem::where('station', 'bar')->sole()->status)->toBe('at_bar');
    (new GuestBarReleaseService)->markReady($request);
    expect(GuestRequestItem::where('station', 'bar')->sole()->released_by_user_id)->toBe($next->id);
});

it('alerts managers once when drinks wait 5 minutes with no bartender, and once about overlapping shifts', function () {
    $f = arFixture();
    $manager = arUser('Manager Mo', 'manager');
    $this->travelTo(Carbon::parse('2026-10-01 20:00:00'));
    (new GuestRequestService)->confirm(arSubmit($f), $f['emeka']);
    $f['bartenderShift']->update(['ended_at' => now()]);

    $this->artisan('guest:bar-monitor')->assertSuccessful();
    expect(BarShiftWaitLog::open()->count())->toBe(1);
    expect($manager->notifications()->count())->toBe(0);

    $this->travelTo(Carbon::parse('2026-10-01 20:06:00'));
    $this->artisan('guest:bar-monitor')->assertSuccessful();
    $this->artisan('guest:bar-monitor')->assertSuccessful();
    expect($manager->notifications()->count())->toBe(1);
    expect(BarShiftWaitLog::sole()->alerted_manager_at)->not->toBeNull();

    arShift(arUser('Late Bar', 'bartender'), 'bartender');
    $this->artisan('guest:bar-monitor')->assertSuccessful();
    expect(BarShiftWaitLog::sole()->ended_at)->not->toBeNull();

    // Two bartender shifts at once: one alert, resolved when one ends.
    $extra = arShift(arUser('Extra Bar', 'bartender'), 'bartender');
    $this->artisan('guest:bar-monitor')->assertSuccessful();
    $this->artisan('guest:bar-monitor')->assertSuccessful();
    expect(BarShiftConflictLog::open()->count())->toBe(1);
    expect($manager->notifications()->count())->toBe(2);

    $extra->update(['ended_at' => now()]);
    $this->artisan('guest:bar-monitor')->assertSuccessful();
    expect(BarShiftConflictLog::sole()->resolved_at)->not->toBeNull();
});

it('refuses the full payment screen for a table with guest orders (D17), and leaves other tables alone', function () {
    $f = arFixture();
    $request = arSubmit($f, 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]]);
    (new GuestRequestService)->confirm($request, $f['emeka']);
    (new GuestBarReleaseService)->markReady($request);
    $barOrder = Order::where('destination', 'bar')->sole();
    $barOrder->update(['status' => 'served']);

    Livewire::actingAs($f['emeka'])
        ->test('pos')
        ->set('selectedTableId', (string) $f['table']->id)
        ->assertSee('Guest QR table — use Mark Paid.')
        ->call('processPayment', [], 1000.0, 'cash')
        ->assertReturned(false)
        ->assertNotified('Guest QR table — use Mark Paid.');

    expect(Order::whereKey($barOrder->id)->exists())->toBeTrue();
    expect($barOrder->fresh()->status)->toBe('served');

    // A normal table still pays through it.
    $other = TableModel::create(['name' => 'Table 9', 'capacity' => 2, 'status' => 'available', 'location' => 'Main']);
    $normal = collect((new OrderSplitter)->handle([(string) $f['beer']->id => ['name' => 'Star Beer', 'price' => 1000, 'quantity' => 1]], $other->id, $f['emeka']->id, ['status' => 'pending', 'shift_id' => $f['emekaShift']->id]))->sole();
    $normal->update(['status' => 'served']);

    Livewire::actingAs($f['emeka'])
        ->test('pos')
        ->set('selectedTableId', (string) $other->id)
        ->assertDontSee('Guest QR table — use Mark Paid.')
        ->call('processPayment', [], 1000.0, 'cash')
        ->assertReturned(true);
});

it('ignores a price override or line identity from any caller without the guest marker', function () {
    $f = arFixture();

    $orders = (new OrderSplitter)->handle([(string) $f['beer']->id => [
        'name' => 'Star Beer', 'price' => 1, 'quantity' => 1,
        'unit_price_override' => 5, 'line_type' => 'menu_item', 'line_id' => $f['jollof']->id,
    ]], $f['table']->id, $f['emeka']->id, ['status' => 'pending', 'shift_id' => $f['emekaShift']->id]);

    $line = OrderItem::where('order_id', $orders[0]->id)->sole();
    expect($line->item_type)->toBe('product');
    expect($line->product_id)->toBe($f['beer']->id);
    expect((float) $line->unit_price)->toBe(1000.0);
    expect($line->chips)->toBeNull();
    expect($line->note)->toBeNull();
});

it('shows the guest the right status for every stage', function () {
    $f = arFixture();
    $device = str_repeat('a', 32);
    $request = arSubmit($f);
    $get = fn () => $this->withCredentials()->withUnencryptedCookie('selum_gd', $device)->getJson('/m/'.$f['table']->qr_token.'/requests')->json('requests.0');

    expect($get())->toMatchArray(['status_label' => 'Waiting for waiter', 'live' => true]);

    (new GuestRequestService)->confirm($request, $f['emeka']);
    $r = $get();
    expect($r['status_label'])->toBe('Confirmed by Emeka');
    expect(collect($r['lines'])->pluck('status_label', 'name')->all())->toBe(['Star Beer' => 'At the bar', 'Jollof Rice' => 'Preparing']);

    (new GuestBarReleaseService)->markReady($request);
    (new KitchenOrderService)->markReady(Order::where('destination', 'kitchen')->value('id'), $f['chef']->id);
    $r = $get();
    expect(collect($r['lines'])->pluck('status_label', 'name')->all())->toBe(['Star Beer' => 'Ready', 'Jollof Rice' => 'Ready']);
    expect($r['live'])->toBeFalse();

    $removed = arSubmit($f, 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2]]);
    (new GuestRequestService)->confirm($removed, $f['emeka']);
    (new GuestBarReleaseService)->remove($removed->items()->sole(), 'Out of stock');
    expect($get()['lines'][0]['status_label'])->toBe('Unavailable: Out of stock');

    $returned = arSubmit($f, 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]]);
    (new GuestRequestService)->confirm($returned, $f['emeka']);
    $f['emekaShift']->update(['ended_at' => now()]);
    (new GuestBarReleaseService)->markReady($returned);
    expect($get()['lines'][0]['status_label'])->toBe('Finding a waiter');
});

it('accepts from the waiter kiosk strip with the waiter\'s PIN, and refuses a wrong PIN', function () {
    $f = arFixture();
    $request = arSubmit($f);

    Livewire::test('guest-orders-strip')
        ->assertSee('Table 5')
        ->assertSee('Star Beer')
        ->assertDontSee('Bar shift not started');

    Livewire::test('guest-orders-strip')->call('accept', $request->id, '0000')->assertNotified('Incorrect PIN');
    expect($request->fresh()->status)->toBe('pending');

    Livewire::test('guest-orders-strip')->call('accept', $request->id, '4826')->assertNotified("{$request->ref} accepted");
    expect($request->fresh()->status)->toBe('confirmed');
    expect(GuestTableSession::sole()->assigned_waiter_user_id)->toBe($f['emeka']->id);
});

it('marks guest drinks ready from the bar display for bar staff only (D33)', function () {
    $f = arFixture();
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);
    $request = arSubmit($f);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    // A waiter can't open the bar display at all.
    $this->actingAs($f['tunde'])->get(\App\Filament\Pages\BarDisplay::getUrl())->assertForbidden();
    expect(Order::where('destination', 'bar')->count())->toBe(0);

    Livewire::actingAs($f['bartender'])
        ->test(\App\Filament\Pages\BarDisplay::class)
        ->assertSee('GUEST · Table 5')
        ->assertSee('Cold')
        ->call('markGuestReady', $request->id)
        ->assertNotified('Table 5 — ready');

    expect(Order::where('destination', 'bar')->sole()->status)->toBe('ready');
});
