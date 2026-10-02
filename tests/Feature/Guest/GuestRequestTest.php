<?php

use App\Filament\Resources\GuestRequests\Pages\ListGuestRequests;
use App\Filament\Resources\GuestRequests\Pages\ViewGuestRequest;
use App\Models\Category;
use App\Models\ChipGroup;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTableSession;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Room;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\Guest\GuestMenuService;
use App\Services\Guest\GuestRequestService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Phase 2 — guests browse, build a cart and send a REQUEST. Nothing here
 * creates orders, moves stock, touches folios or alerts staff.
 */
function grFixture(): array
{
    // Bar is the first consumer warehouse, kitchen the second (InventoryService).
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $drinks = Category::create(['name' => 'Beers', 'type' => 'drink']);
    $food = Category::create(['name' => 'Rice', 'type' => 'food']);
    DB::table('category_chip_group')->insert([
        ['category_id' => $drinks->id, 'chip_group_id' => ChipGroup::where('name', 'Temperature')->value('id')],
        ['category_id' => $food->id, 'chip_group_id' => ChipGroup::where('name', 'Food extras')->value('id')],
    ]);

    $beer = Product::create(['name' => 'Star Beer', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true, 'description' => 'Crisp lager']);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    $jollof = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-GR-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]);

    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Cache::flush();

    return compact('bar', 'kitchen', 'drinks', 'food', 'beer', 'jollof', 'table');
}

function grDevice(string $char = 'a'): string
{
    return str_repeat($char, 32);
}

function grLines(array $f, array $overrides = []): array
{
    return $overrides ?: [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold']],
        ['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1, 'chips' => ['Extra pepper', 'No onions'], 'note' => 'No crayfish please'],
    ];
}

/** POST a request the way the guest page does. */
function grSubmit($test, array $f, array $lines, string $device = 'a', ?string $token = null)
{
    return $test->withCredentials()->withUnencryptedCookie('selum_gd', grDevice($device))
        ->postJson('/m/'.($token ?? $f['table']->qr_token).'/requests', ['lines' => $lines]);
}

it('creates zero session rows for a guest GET and POST', function () {
    $f = grFixture();
    DB::table('sessions')->delete();

    $this->get('/m/'.$f['table']->qr_token)->assertOk();
    grSubmit($this, $f, grLines($f))->assertCreated();
    $this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice())->getJson('/m/'.$f['table']->qr_token.'/requests')->assertOk();

    expect(DB::table('sessions')->count())->toBe(0);
});

it('sets the selum_gd device cookie on the first visit: httpOnly, Secure, SameSite=Lax, 30 days', function () {
    $f = grFixture();

    $cookie = collect($this->get('/m/'.$f['table']->qr_token)->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'selum_gd');

    expect($cookie)->not->toBeNull();
    expect($cookie->getValue())->toMatch('/^[A-Za-z0-9]{32}$/');
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->isSecure())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->timestamp);

    // A phone that already has one keeps it.
    $again = $this->withUnencryptedCookie('selum_gd', grDevice())->get('/m/'.$f['table']->qr_token);
    expect(collect($again->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'selum_gd'))->toBeNull();
});

it('submits a request with items, a ref and price snapshots — and creates no order, stock or folio movement', function () {
    $f = grFixture();
    Carbon::setTestNow('2026-10-01 20:00:00');

    $response = grSubmit($this, $f, grLines($f))->assertCreated()->assertJsonPath('ok', true);

    $request = GuestRequest::sole();
    expect($request->ref)->toBe('T5-0001');
    expect($response->json('request.ref'))->toBe('T5-0001');
    expect($request->status)->toBe('pending');
    expect($request->device_id)->toBe(grDevice());
    expect((float) $request->total_snapshot)->toBe(6500.0);

    $beerLine = GuestRequestItem::where('item_type', 'product')->sole();
    expect($beerLine->station)->toBe('bar');
    expect((float) $beerLine->unit_price_snapshot)->toBe(1000.0);
    expect($beerLine->chips)->toBe(['Cold']);

    $jollofLine = GuestRequestItem::where('item_type', 'menu_item')->sole();
    expect($jollofLine->station)->toBe('kitchen');
    expect($jollofLine->chips)->toBe(['Extra pepper', 'No onions']);
    expect($jollofLine->note)->toBe('No crayfish please');

    expect(Order::count())->toBe(0);
    expect(DB::table('order_items')->count())->toBe(0);
    expect(DB::table('inventory_transactions')->count())->toBe(0);
    expect(DB::table('folio_lines')->count())->toBe(0);
    expect((float) InventoryItem::where('product_id', $f['beer']->id)->value('quantity'))->toBe(50.0);
});

it('keeps the snapshot price when the menu price changes after submit', function () {
    $f = grFixture();
    grSubmit($this, $f, grLines($f))->assertCreated();

    $f['beer']->update(['price' => 1500]);
    $f['jollof']->update(['sale_price' => 6000]);

    expect((float) GuestRequestItem::where('item_type', 'product')->value('unit_price_snapshot'))->toBe(1000.0);
    expect((float) GuestRequestItem::where('item_type', 'menu_item')->value('unit_price_snapshot'))->toBe(4500.0);
    expect((float) GuestRequest::sole()->total_snapshot)->toBe(6500.0);
});

it('refuses an item that just became unavailable with 422, creating nothing', function () {
    $f = grFixture();
    $f['jollof']->update(['available_for_sale' => false]);

    grSubmit($this, $f, grLines($f))
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'code' => 'unavailable', 'item' => 'm'.$f['jollof']->id])
        ->assertJsonPath('message', 'Sorry, Jollof Rice just sold out — we\'ve removed it from your order.');

    // A drink with no stock left at the bar is just as unavailable.
    InventoryItem::where('product_id', $f['beer']->id)->update(['quantity' => 0]);
    grSubmit($this, $f, [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]])->assertStatus(422)->assertJsonPath('code', 'unavailable');

    expect(GuestRequest::count())->toBe(0);
    expect(GuestTableSession::count())->toBe(0);
});

it('rejects invalid chips, an over-long note, bad quantities and too many lines', function (Closure $lines, string $code) {
    $f = grFixture();

    grSubmit($this, $f, $lines($f))->assertStatus(422)->assertJsonPath('code', $code);
    expect(GuestRequest::count())->toBe(0);
})->with([
    'a chip from another category' => [fn ($f) => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1, 'chips' => ['Extra pepper']]], 'bad_chips'],
    'a made-up chip' => [fn ($f) => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1, 'chips' => ['Shaken']]], 'bad_chips'],
    'two picks from a pick-one group' => [fn ($f) => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1, 'chips' => ['Cold', 'Not cold']]], 'bad_chips'],
    'a 101-character note' => [fn ($f) => [['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1, 'note' => str_repeat('x', 101)]], 'bad_note'],
    'qty 0' => [fn ($f) => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 0]], 'bad_qty'],
    'qty 21' => [fn ($f) => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 21]], 'bad_qty'],
    '31 lines' => [fn ($f) => array_fill(0, 31, ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]), 'bad_lines'],
    'no lines' => [fn ($f) => [], 'bad_lines'],
    'an unknown item' => [fn ($f) => [['type' => 'product', 'id' => 99999, 'qty' => 1]], 'bad_item'],
]);

it('strips tags from a note', function () {
    $f = grFixture();
    grSubmit($this, $f, [['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1, 'note' => '<b>no</b>   <script>x</script>onions']])->assertCreated();

    expect(GuestRequestItem::sole()->note)->toBe('no xonions');
});

it('limits pending requests to 3 per phone and 10 per table', function () {
    $f = grFixture();
    $one = [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]];
    $service = new GuestRequestService;

    foreach (range(1, 3) as $i) {
        $service->submit($f['table']->qr_token, grDevice(), $one);
    }

    grSubmit($this, $f, $one)->assertStatus(429)->assertJsonPath('code', 'too_many_pending');

    foreach (range(1, 7) as $i) {
        $service->submit($f['table']->qr_token, grDevice(chr(ord('b') + $i)), $one);
    }

    expect(GuestRequest::where('table_id', $f['table']->id)->where('status', 'pending')->count())->toBe(10);
    grSubmit($this, $f, $one, 'z')->assertStatus(429)->assertJsonPath('code', 'table_busy');
});

it('opens a table session on the first submit only; a second phone joins it; a scan opens nothing', function () {
    $f = grFixture();

    $this->get('/m/'.$f['table']->qr_token)->assertOk();
    $this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice('b'))->getJson('/m/'.$f['table']->qr_token.'/requests')->assertOk();
    expect(GuestTableSession::count())->toBe(0);

    grSubmit($this, $f, grLines($f), 'a')->assertCreated();
    grSubmit($this, $f, grLines($f), 'b')->assertCreated();

    $session = GuestTableSession::sole();
    expect($session->isOpen())->toBeTrue();
    expect($session->table_id)->toBe($f['table']->id);
    expect(GuestRequest::pluck('guest_table_session_id')->unique()->all())->toBe([$session->id]);
    expect($session->assigned_waiter_user_id)->toBeNull();
});

it('can never hold two open sessions for one table, even if the service lock were bypassed', function () {
    $f = grFixture();
    $service = new GuestRequestService;
    $line = [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]];

    // Back-to-back "first" submits from two phones.
    $service->submit($f['table']->qr_token, grDevice('a'), $line);
    $service->submit($f['table']->qr_token, grDevice('b'), $line);
    expect(GuestTableSession::open()->where('table_id', $f['table']->id)->count())->toBe(1);

    // And the database itself refuses a second open session.
    expect(fn () => GuestTableSession::create([
        'public_id' => 'X'.str_repeat('y', 15), 'table_id' => $f['table']->id, 'opened_at' => now(), 'last_activity_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('lets a phone cancel its own pending request only', function () {
    $f = grFixture();
    $ref = grSubmit($this, $f, grLines($f), 'a')->json('request.ref');
    $url = '/m/'.$f['table']->qr_token.'/requests/'.$ref.'/cancel';

    $this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice('b'))->postJson($url)->assertStatus(403)->assertJsonPath('code', 'not_yours');

    $this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice('a'))->postJson($url)
        ->assertOk()->assertJsonPath('request.status', 'cancelled_by_guest');

    expect(GuestRequest::sole()->status)->toBe('cancelled_by_guest');
    expect(GuestRequestItem::pluck('status')->unique()->all())->toBe(['cancelled']);

    $this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice('a'))->postJson($url)->assertStatus(409)->assertJsonPath('code', 'not_pending');
});

it('refuses ordering from a room with nobody checked in, with a friendly message (Phase 5)', function () {
    $f = grFixture();
    $room = Room::create(['number' => '7', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);

    grSubmit($this, $f, grLines($f), 'a', $room->qr_token)
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'code' => 'not_staying', 'message' => 'Ordering is available during your stay.']);

    $this->get('/m/'.$room->qr_token)->assertOk()->assertSee('Ordering is available during your stay.')->assertSee('"mode":"browse"', false);
    expect(GuestRequest::count())->toBe(0);
});

it('rejects a POST that is not JSON with 415', function () {
    $f = grFixture();

    $this->withUnencryptedCookie('selum_gd', grDevice())
        ->post('/m/'.$f['table']->qr_token.'/requests', ['lines' => grLines($f)])
        ->assertStatus(415)
        ->assertJsonPath('code', 'json_only');

    expect(GuestRequest::count())->toBe(0);
});

it('rate-limits submits to 6 per 10 minutes per phone, but lets 25 phones on one Wi-Fi all load the menu', function () {
    $f = grFixture();
    $one = [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]];

    foreach (range(1, 6) as $i) {
        // Cancel each so the 3-pending cap doesn't bite first.
        $ref = grSubmit($this, $f, $one)->assertCreated()->json('request.ref');
        $this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice())->postJson('/m/'.$f['table']->qr_token.'/requests/'.$ref.'/cancel')->assertOk();
    }

    grSubmit($this, $f, $one)->assertStatus(429);

    foreach (range(1, 25) as $i) {
        $this->withUnencryptedCookie('selum_gd', str_pad((string) $i, 32, 'p', STR_PAD_LEFT))
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get('/m/'.$f['table']->qr_token)
            ->assertOk();
    }
});

it('expires 3-hour-old pending requests and closes idle sessions', function () {
    $f = grFixture();
    $this->travelTo(Carbon::parse('2026-10-01 19:00:00'));
    grSubmit($this, $f, grLines($f))->assertCreated();

    $this->travelTo(Carbon::parse('2026-10-01 21:59:00'));
    $this->artisan('guest:expire-stale')->assertSuccessful();
    expect(GuestRequest::sole()->status)->toBe('pending');
    expect(GuestTableSession::sole()->isOpen())->toBeTrue();

    $this->travelTo(Carbon::parse('2026-10-01 22:01:00'));
    $this->artisan('guest:expire-stale')->assertSuccessful();

    expect(GuestRequest::sole()->status)->toBe('expired');
    expect(GuestRequestItem::pluck('status')->unique()->all())->toBe(['cancelled']);
    $session = GuestTableSession::sole();
    expect($session->isOpen())->toBeFalse();
    expect($session->close_reason)->toBe('auto_stale');
});

it('restarts the ref sequence at the 9am Lagos business-day rollover, not at midnight', function () {
    $f = grFixture();
    $one = [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]];
    $service = new GuestRequestService;

    // 22:00 Lagos and 07:30 Lagos the next morning are the SAME business day.
    $this->travelTo(Carbon::parse('2026-10-01 21:00:00', 'UTC'));
    expect($service->submit($f['table']->qr_token, grDevice('a'), $one)->ref)->toBe('T5-0001');
    $this->travelTo(Carbon::parse('2026-10-02 06:30:00', 'UTC'));
    expect($service->submit($f['table']->qr_token, grDevice('b'), $one)->ref)->toBe('T5-0002');

    // 09:30 Lagos starts a new business day.
    $this->travelTo(Carbon::parse('2026-10-02 08:30:00', 'UTC'));
    $third = $service->submit($f['table']->qr_token, grDevice('c'), $one);
    expect($third->ref)->toBe('T5-0001');
    expect($third->business_date->toDateString())->toBe('2026-10-02');
});

it('drops a sold-out item from /availability without reading the POS caches', function () {
    $f = grFixture();
    $url = '/m/'.$f['table']->qr_token.'/availability';
    Cache::put('menu_items_all', 'POS cache must not be read', 600);
    Cache::put('products_all', 'POS cache must not be read', 600);

    $this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice())->getJson($url)->assertOk()->assertJsonMissing(['unavailable' => ['m'.$f['jollof']->id]]);

    $f['jollof']->update(['available_for_sale' => false]);

    expect($this->withCredentials()->withUnencryptedCookie('selum_gd', grDevice())->getJson($url)->json('unavailable'))->toContain('m'.$f['jollof']->id);
    expect(Cache::get('menu_items_all'))->toBe('POS cache must not be read');
});

it('shows Popular tonight only with at least 3 qualifying sellers, and never an unavailable one', function () {
    $f = grFixture();
    $this->travelTo(Carbon::parse('2026-10-01 20:00:00'));
    $wine = Product::create(['name' => 'Red Wine', 'price' => 9000, 'category_id' => $f['drinks']->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $wine->id, 'warehouse_id' => $f['bar']->id, 'quantity' => 10]);

    $sell = function (string $type, int $id, int $qty) {
        $order = Order::create(['order_number' => 'ORD-POP-'.uniqid(), 'status' => 'paid', 'destination' => 'bar', 'total_amount' => 1, 'amount_paid' => 1]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'item_type' => $type, 'product_id' => $type === 'product' ? $id : null,
            'menu_item_id' => $type === 'menu_item' ? $id : null, 'product_name' => 'x', 'quantity' => $qty,
            'unit_price' => 1, 'subtotal' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $sell('product', $f['beer']->id, 9);
    $sell('menu_item', $f['jollof']->id, 5);
    expect((new GuestMenuService)->popularTonight())->toBe([]);

    $sell('product', $wine->id, 2);
    Cache::flush();
    expect(array_column((new GuestMenuService)->popularTonight(), 'key'))->toBe(['p'.$f['beer']->id, 'm'.$f['jollof']->id, 'p'.$wine->id]);

    $f['jollof']->update(['available_for_sale' => false]);
    expect((new GuestMenuService)->popularTonight())->toBe([]);
});

it('lets a manager list and view guest requests, refuses a waiter, and offers no create, edit or delete', function () {
    $this->seed(ShieldSeeder::class);
    $f = grFixture();
    grSubmit($this, $f, grLines($f))->assertCreated();
    $request = GuestRequest::sole();

    $manager = User::factory()->create();
    $manager->assignRole('manager');

    Livewire::actingAs($manager)
        ->test(ListGuestRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertSee('T5-0001');

    Livewire::actingAs($manager)
        ->test(ViewGuestRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Jollof Rice')
        ->assertSee('Extra pepper');

    expect(\App\Filament\Resources\GuestRequests\GuestRequestResource::getPages())->toHaveKeys(['index', 'view'])->not->toHaveKeys(['create', 'edit']);
    $this->actingAs($manager);
    expect(\App\Filament\Resources\GuestRequests\GuestRequestResource::canCreate())->toBeFalse();
    expect(\App\Filament\Resources\GuestRequests\GuestRequestResource::canEdit($request))->toBeFalse();
    expect(\App\Filament\Resources\GuestRequests\GuestRequestResource::canDelete($request))->toBeFalse();

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $this->actingAs($waiter);
    expect(\App\Filament\Resources\GuestRequests\GuestRequestResource::canViewAny())->toBeFalse();
});

it('embeds the whole menu in one page, hiding nothing but showing unavailable items as gone', function () {
    $f = grFixture();
    $f['beer']->update(['description' => 'Crisp lager']);
    $f['jollof']->update(['available_for_sale' => false]);

    $page = $this->get('/m/'.$f['table']->qr_token)->assertOk();
    $boot = json_decode(str($page->getContent())->between('<script type="application/json" id="guest-boot">', '</script>')->toString(), true);

    expect($boot['mode'])->toBe('order');
    expect($boot['place'])->toBe('Table 5');
    expect(collect($boot['menu']['tabs']['drinks'])->pluck('name')->all())->toBe(['Beers']);
    expect($boot['menu']['tabs']['drinks'][0]['items'][0])->toMatchArray(['key' => 'p'.$f['beer']->id, 'price' => 1000, 'description' => 'Crisp lager', 'note' => false]);
    expect($boot['menu']['tabs']['drinks'][0]['items'][0]['chips'][0])->toMatchArray(['group' => 'Temperature', 'selection' => 'single', 'options' => ['Cold', 'Not cold']]);
    expect($boot['menu']['tabs']['food'][0]['items'][0])->toMatchArray(['key' => 'm'.$f['jollof']->id, 'note' => true]);
    expect($boot['unavailable'])->toContain('m'.$f['jollof']->id);
    expect($page->getContent())->not->toContain('livewire')->not->toContain('csrf');
});
