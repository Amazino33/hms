<?php

use App\Filament\Pages\BarDisplay;
use App\Models\Category;
use App\Models\FolioLine;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\RoomRequestApprovalService;
use App\Services\OrderSplitter;
use App\Services\PinAuthService;
use App\Services\ReservationService;
use Database\Seeders\PagePermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 7B (D33): one queue, one button. A guest drink card's Mark Ready
 * creates the real bar order and marks it ready in one transaction.
 */
function bmrUser(string $name, ?string $role = null): User
{
    $user = User::factory()->create(['name' => $name]);

    if ($role) {
        $user->assignRole(Role::firstOrCreate(['name' => $role]));
    }

    return $user;
}

function bmrFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Beers', 'type' => 'drink']);
    DB::table('category_chip_group')->insert([
        ['category_id' => $drinks->id, 'chip_group_id' => DB::table('chip_groups')->where('name', 'Temperature')->value('id')],
    ]);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);

    $emeka = bmrUser('Emeka Obi', 'waiter');
    (new PinAuthService)->setPin($emeka, '4826');
    $emekaShift = Shift::create(['user_id' => $emeka->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $bartender = bmrUser('Bisi Bar', 'bartender');
    $bartenderShift = Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    $receptionist = bmrUser('Blessing Desk', 'receptionist');

    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $room = Room::create(['number' => '7', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Ada Eze', 'guest_phone' => '08061234567',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    $stay = (new BookingService)->checkIn($booking, $receptionist->id);
    Cache::flush();

    return compact('bar', 'beer', 'emeka', 'emekaShift', 'bartender', 'bartenderShift', 'receptionist', 'table', 'room', 'stay');
}

/** A confirmed table request: 2× Star Beer (Cold), waiting at the bar. */
function bmrTableCard(array $f, int $qty = 2): GuestRequest
{
    $request = (new GuestRequestService)->submit($f['table']->qr_token, str_repeat('a', 32), [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => $qty, 'chips' => ['Cold']],
    ]);
    (new GuestRequestService)->confirm($request, $f['emeka']);

    return $request->fresh();
}

function bmrRoomCard(array $f): GuestRequest
{
    $request = (new GuestRequestService)->submit($f['room']->qr_token, str_repeat('r', 32), [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold']],
    ], 'none');
    (new RoomRequestApprovalService)->approve($request, $f['receptionist']);

    return $request->fresh();
}

function bmrBeer(array $f): float
{
    return (float) InventoryItem::where('product_id', $f['beer']->id)->where('warehouse_id', $f['bar']->id)->value('quantity');
}

it('creates a table guest order AND marks it ready in one tap: stock once, waiter shift, locked price, bartender credited', function () {
    $f = bmrFixture();
    $request = bmrTableCard($f);
    $f['beer']->update(['price' => 2500]); // after the guest ordered

    expect((new GuestBarReleaseService)->markReady($request))->toBe(GuestBarReleaseService::READY);

    $order = Order::where('destination', 'bar')->sole();
    expect($order->status)->toBe('ready');
    expect($order->user_id)->toBe($f['emeka']->id);
    expect($order->shift_id)->toBe($f['emekaShift']->id);
    expect((float) OrderItem::where('order_id', $order->id)->sole()->unit_price)->toBe(1000.0);
    expect(bmrBeer($f))->toBe(18.0);
    expect(InventoryTransaction::where('reference', "order:{$order->id}")->count())->toBe(1);

    $line = GuestRequestItem::sole();
    expect($line->status)->toBe('released');
    expect($line->released_by_user_id)->toBe($f['bartender']->id);
});

it('does the same for a room card: one room order, ready, stock once, one folio charge — ready never run twice (D24)', function () {
    $f = bmrFixture();
    $request = bmrRoomCard($f);

    expect((new GuestBarReleaseService)->markReady($request))->toBe(GuestBarReleaseService::READY);

    $order = Order::where('destination', 'bar')->sole();
    expect($order->status)->toBe('ready');
    expect($order->booking_id)->toBe($f['stay']->id);
    expect($order->processed_by_user_id)->toBe($f['bartender']->id);
    expect(bmrBeer($f))->toBe(18.0);
    expect(InventoryTransaction::where('reference', "order:{$order->id}")->count())->toBe(1);
    expect(FolioLine::where('order_id', $order->id)->count())->toBe(1);
    expect(GuestRequestItem::sole()->delivery_status)->toBe('awaiting_dispatch');
});

it('gives one order, ready once, when Mark Ready is tapped twice', function () {
    $f = bmrFixture();
    $request = bmrTableCard($f);
    $service = new GuestBarReleaseService;

    $service->markReady($request);
    expect(fn () => $service->markReady($request))->toThrow(Exception::class, 'already been marked ready');

    expect(Order::where('destination', 'bar')->count())->toBe(1);
    expect(bmrBeer($f))->toBe(18.0);
});

it('refuses with no bartender shift, and asks who is marking it ready when two are open (D1)', function () {
    $f = bmrFixture();
    $request = bmrTableCard($f);

    $f['bartenderShift']->update(['status' => 'closed', 'ended_at' => now()]);
    expect(fn () => (new GuestBarReleaseService)->markReady($request))->toThrow(Exception::class, 'Start your bartender shift');
    expect(Order::count())->toBe(0);
    expect(GuestRequestItem::sole()->status)->toBe('at_bar');

    $f['bartenderShift']->update(['status' => 'active', 'ended_at' => null]);
    $second = bmrUser('Tayo Bar', 'bartender');
    Shift::create(['user_id' => $second->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    expect(fn () => (new GuestBarReleaseService)->markReady($request))->toThrow(Exception::class, 'choose who is marking this ready');
    expect(Order::count())->toBe(0);

    (new GuestBarReleaseService)->markReady($request, $second);
    expect(GuestRequestItem::sole()->released_by_user_id)->toBe($second->id);
    expect(Order::where('destination', 'bar')->sole()->status)->toBe('ready');
});

it('hands the drinks back to the waiters, creating nothing, when the waiter has left (D3)', function () {
    $f = bmrFixture();
    $request = bmrTableCard($f);
    $f['emekaShift']->update(['status' => 'closed', 'ended_at' => now()]);

    expect((new GuestBarReleaseService)->markReady($request))->toBe(GuestBarReleaseService::RETURNED_TO_WAITERS);

    expect(GuestRequestItem::sole()->status)->toBe('needs_waiter');
    expect(Order::count())->toBe(0);
    expect(bmrBeer($f))->toBe(20.0);
});

it('still lets the bar reduce or remove a line with a reason before Mark Ready', function () {
    $f = bmrFixture();
    $request = bmrTableCard($f, 3);
    $line = GuestRequestItem::sole();
    $this->seed(PagePermissionsSeeder::class);

    Livewire::actingAs($f['bartender'])->test(BarDisplay::class)
        ->call('reduceGuestLine', $line->id, 1, 'Out of stock')
        ->call('markGuestReady', $request->id)
        ->assertNotified('Table 5 — ready');

    expect($line->fresh()->quantity_final)->toBe(1);
    expect(OrderItem::sole()->quantity)->toBe(1);
    expect(bmrBeer($f))->toBe(19.0);

    $other = bmrTableCard($f);
    $otherLine = $other->items()->sole();
    Livewire::actingAs($f['bartender'])->test(BarDisplay::class)->call('removeGuestLine', $otherLine->id, 'Wrong item');
    expect($otherLine->fresh()->status)->toBe('removed');
    expect($otherLine->fresh()->removed_reason)->toBe('Wrong item');
});

it('marks a normal (non-guest) bar order ready exactly as before', function () {
    $f = bmrFixture();
    $this->seed(PagePermissionsSeeder::class);
    $order = collect((new OrderSplitter)->handle([(string) $f['beer']->id => ['name' => 'Star Beer', 'price' => 1000, 'quantity' => 2]], $f['table']->id, $f['emeka']->id, ['status' => 'pending', 'shift_id' => $f['emekaShift']->id]))->sole();
    expect(bmrBeer($f))->toBe(18.0); // a table bar order deducts at creation

    Livewire::actingAs($f['bartender'])->test(BarDisplay::class)->call('markAsReady', $order->id);

    $order->refresh();
    expect($order->status)->toBe('ready');
    expect($order->processed_by_user_id)->toBe($f['bartender']->id);
    expect(bmrBeer($f))->toBe(18.0); // not deducted again
    expect(DB::table('notifications')->where('data', 'like', '%Ready!%')->count())->toBeGreaterThan(0);
});

it('shows guest cards and normal orders in ONE queue, oldest first, with no separate guest column', function () {
    $f = bmrFixture();
    $this->seed(PagePermissionsSeeder::class);
    // Normal orders on another table (on Table 5 they'd rightly trigger "Same guests?").
    $other = TableModel::create(['name' => 'Table 9', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $older = collect((new OrderSplitter)->handle([(string) $f['beer']->id => ['name' => 'Star Beer', 'price' => 1000, 'quantity' => 1]], $other->id, $f['emeka']->id, ['status' => 'pending', 'shift_id' => $f['emekaShift']->id]))->sole();
    $this->travel(1)->minutes();
    $guest = bmrTableCard($f);
    $this->travel(1)->minutes();
    $newer = collect((new OrderSplitter)->handle([(string) $f['beer']->id => ['name' => 'Star Beer', 'price' => 1000, 'quantity' => 1]], $other->id, $f['emeka']->id, ['status' => 'pending', 'shift_id' => $f['emekaShift']->id]))->sole();
    Cache::flush();

    Livewire::actingAs($f['bartender'])->test(BarDisplay::class)
        ->assertSeeInOrder([$older->order_number, 'GUEST · Table 5', $guest->ref, 'Accepted by', 'Emeka', $newer->order_number])
        ->assertSee('MARK READY')
        ->assertDontSee('Release')
        ->assertDontSee('Who is releasing');

    expect(File::exists(resource_path('views/livewire/bar-guest-queue.blade.php')))->toBeFalse();
});

it('tells the guest a marked-ready drink is "Ready"', function () {
    $f = bmrFixture();
    $request = bmrTableCard($f);
    (new GuestBarReleaseService)->markReady($request);

    $lines = $this->withCredentials()->withUnencryptedCookie('selum_gd', str_repeat('a', 32))
        ->getJson('/m/'.$f['table']->qr_token.'/requests')->json('requests.0.lines');

    expect($lines[0]['status_label'])->toBe('Ready');
});

it('leaves no "Release" wording anywhere staff or guests can read', function () {
    $views = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'))
        ->mapWithKeys(fn ($f) => [$f->getRelativePathname() => File::get($f->getPathname())]);

    foreach ($views as $path => $source) {
        $text = preg_replace('/\{\{--.*?--\}\}/s', '', $source); // Blade comments aren't shown
        $text = preg_replace('/released_by_user_id|released_at|\'released\'|"released"/', '', $text);
        expect(preg_match('/\b[Rr]eleas(e|ed|ing)\b/', $text))->toBe(0, "{$path} still says Release");
    }

    // Quoted sentences (messages, labels) in the guest services and the bar page.
    $sources = collect(glob(app_path('Services/Guest/*.php')))->push(app_path('Filament/Pages/BarDisplay.php'));
    foreach ($sources as $path) {
        // Real string literals only (PHP's tokenizer) — never comments.
        foreach (token_get_all(File::get($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && str_contains($token[1], ' ')) {
                expect(preg_match('/\b[Rr]eleas(e|ed|ing)\b/', $token[1]))->toBe(0, basename($path).": {$token[1]}");
            }
        }
    }
});

it('only lets GuestBarReleaseService::markReady() call release() (architecture)', function () {
    $service = File::get(app_path('Services/Guest/GuestBarReleaseService.php'));
    expect($service)->toMatch('/private function release\(/');

    // Inside the service, the only call sits in markReady().
    preg_match('/public function markReady\(.*?\n    }\n/s', $service, $markReady);
    expect(substr_count($service, '->release('))->toBe(1);
    expect(substr_count($markReady[0] ?? '', '->release('))->toBe(1);

    // Nowhere else in the app.
    $callers = collect(File::allFiles(app_path()))->merge(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), '.php'))
        ->filter(fn ($f) => preg_match('/(GuestBarReleaseService|\$this|\$service|\$bar)\)?\s*->\s*release\s*\(/', File::get($f->getPathname())))
        ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))
        ->values()->all();
    expect($callers)->toBe(['Services/Guest/GuestBarReleaseService.php']);
});
