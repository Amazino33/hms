<?php

use App\Filament\Pages\RoomOrders;
use App\Filament\Resources\GuestContacts\GuestContactResource;
use App\Models\Booking;
use App\Models\Category;
use App\Models\FolioLine;
use App\Models\GuestContact;
use App\Models\GuestPaymentClaim;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTrustedDevice;
use App\Models\GuestWaiterCall;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\KitchenWasteLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Room;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestClaimService;
use App\Services\Guest\GuestOrderingSettings;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\GuestWhatsapp;
use App\Services\Guest\RoomDeliveryService;
use App\Services\Guest\RoomRequestApprovalService;
use App\Services\KitchenOrderService;
use App\Services\ReservationService;
use App\Services\RoomOrderService;
use App\Services\SettingsService;
use Database\Seeders\PagePermissionsSeeder;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 5 — room ordering: WhatsApp hand-off, reception approval, bar
 * release (D24), porters, delivered/refused (D26), the room bill (D25).
 */
function rmUser(string $name, ?string $role = null): User
{
    $user = User::factory()->create(['name' => $name]);

    if ($role) {
        $user->assignRole(Role::firstOrCreate(['name' => $role]));
    }

    return $user;
}

function rmStay(Room $room, User $receptionist, string $guest = 'Ada Eze'): Booking
{
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => $guest, 'guest_phone' => '0806'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);

    return (new BookingService)->checkIn($booking, $receptionist->id);
}

function rmFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Beers', 'type' => 'drink']);
    $food = Category::create(['name' => 'Rice', 'type' => 'food']);
    DB::table('category_chip_group')->insert([
        ['category_id' => $drinks->id, 'chip_group_id' => DB::table('chip_groups')->where('name', 'Temperature')->value('id')],
    ]);

    $beer = Product::create(['name' => 'Star Beer', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);
    $jollof = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-RM-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RM-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 300, 'category' => 'Grains']);
    Recipe::create(['menu_item_id' => $jollof->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 2]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $receptionist = rmUser('Kemi Desk', 'receptionist');
    $manager = rmUser('Grace Manager', 'manager');
    $bartender = rmUser('Bisi Bar', 'bartender');
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    $chef = rmUser('Chef Tola', 'chef');
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);
    $porter = rmUser('Musa Okafor', 'porter');

    $room = Room::create(['number' => '7', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $empty = Room::create(['number' => '8', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $stay = rmStay($room, $receptionist);
    Cache::flush();

    return compact('bar', 'kitchen', 'beer', 'jollof', 'rice', 'receptionist', 'manager', 'bartender', 'chef', 'porter', 'room', 'empty', 'stay');
}

function rmDevice(string $c = 'a'): string
{
    return str_repeat($c, 32);
}

/** 1x Jollof + 2x Star Beer (Cold, "no ice"). */
function rmSubmit(array $f, string $device = 'a', ?array $lines = null, ?string $channel = 'whatsapp'): GuestRequest
{
    return (new GuestRequestService)->submit($f['room']->qr_token, rmDevice($device), $lines ?? [
        ['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1],
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold'], 'note' => 'no ice'],
    ], $channel);
}

function rmBeerOnly(array $f, string $device = 'a', int $qty = 2): GuestRequest
{
    return rmSubmit($f, $device, [['type' => 'product', 'id' => $f['beer']->id, 'qty' => $qty, 'chips' => ['Cold']]]);
}

function rmBeer(array $f): float
{
    return (float) InventoryItem::where('product_id', $f['beer']->id)->where('warehouse_id', $f['bar']->id)->value('quantity');
}

function rmRice(array $f): float
{
    return (float) IngredientInventoryItem::where('ingredient_id', $f['rice']->id)->where('warehouse_id', $f['kitchen']->id)->value('quantity');
}

function rmBalance(array $f): float
{
    return $f['stay']->fresh()->folio->balance();
}

function rmGet($test, array $f, string $path, string $device = 'a')
{
    return $test->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice($device))->getJson('/m/'.$f['room']->qr_token.$path);
}

/** Approved, released room drinks, waiting for a porter. */
function rmReleasedBeer(array $f, string $device = 'a'): GuestRequest
{
    $request = rmBeerOnly($f, $device);
    (new RoomRequestApprovalService)->approve($request, $f['receptionist']);
    (new GuestBarReleaseService)->markReady($request);

    return $request->fresh('items');
}

it('sets the reception WhatsApp number only when nothing is there yet (Step 0.1)', function () {
    $migration = require database_path('migrations/2026_10_02_100100_set_default_reception_whatsapp.php');

    // Fresh database: the migration already ran.
    expect(GuestOrderingSettings::receptionWhatsapp())->toBe('2348144734612');

    $owner = rmUser('Owner', 'super_admin');
    SettingsService::set(GuestOrderingSettings::RECEPTION_WHATSAPP, '2348011112222', 'string', $owner->id);
    $migration->up();
    expect(GuestOrderingSettings::receptionWhatsapp())->toBe('2348011112222');

    SettingsService::set(GuestOrderingSettings::RECEPTION_WHATSAPP, '', 'string', $owner->id);
    $migration->up();
    expect(GuestOrderingSettings::receptionWhatsapp())->toBe('2348144734612');
});

it('saves a room order against the stay and returns a WhatsApp link with the exact message; refuses a room nobody is staying in', function () {
    $f = rmFixture();

    $response = $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice())
        ->postJson('/m/'.$f['room']->qr_token.'/requests', ['channel' => 'whatsapp', 'lines' => [
            ['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1],
            ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold'], 'note' => 'no ice'],
        ]])->assertCreated();

    $request = GuestRequest::sole();
    expect($request->stay_id)->toBe($f['stay']->id);
    expect($request->room_id)->toBe($f['room']->id);
    expect($request->channel)->toBe('whatsapp');
    expect($request->guest_table_session_id)->toBeNull();
    expect($response->json('request.status_label'))->toBe('Waiting for reception');

    $url = $response->json('whatsapp_url');
    expect($url)->toStartWith('https://wa.me/2348144734612?text=');
    expect(rawurldecode(substr($url, strlen('https://wa.me/2348144734612?text='))))->toBe(
        "🛎 Room 7 order · Ref {$request->ref}\n"
        ."1x Jollof Rice  ₦4,500\n"
        ."2x Star Beer (Cold)  ₦2,000\n"
        ."   Note: no ice\n"
        .'Total: ₦6,500'
    );
    expect($request->ref)->toStartWith('R7-');

    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice())
        ->postJson('/m/'.$f['empty']->qr_token.'/requests', ['channel' => 'whatsapp', 'lines' => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]]])
        ->assertStatus(422)->assertJson(['code' => 'not_staying', 'message' => 'Ordering is available during your stay.']);
    expect(GuestRequest::count())->toBe(1);
});

it('saves channel none with no link, and reception sees "call the room"', function () {
    $f = rmFixture();
    $this->seed(PagePermissionsSeeder::class);

    $response = $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice())
        ->postJson('/m/'.$f['room']->qr_token.'/requests', ['channel' => 'none', 'lines' => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]]])
        ->assertCreated();
    expect($response->json('whatsapp_url'))->toBeNull();
    expect(GuestRequest::sole()->channel)->toBe('none');

    // No reception number at all: WhatsApp can't be used, so it's 'none' too.
    SettingsService::set(GuestOrderingSettings::RECEPTION_WHATSAPP, '', 'string', $f['manager']->id);
    $second = rmBeerOnly($f, 'b');
    expect($second->channel)->toBe('none');
    expect(GuestWhatsapp::orderUrl($second))->toBeNull();

    Livewire::actingAs($f['receptionist'])->test(RoomOrders::class)
        ->assertSee('Room 7')
        ->assertSee('No WhatsApp — call the room')
        ->assertSee('New phone');
});

it('marks only a phone\'s first request of the stay as from a new phone', function () {
    $f = rmFixture();

    expect(rmBeerOnly($f, 'a')->first_from_device)->toBeTrue();
    expect(rmBeerOnly($f, 'a')->first_from_device)->toBeFalse();
    expect(rmBeerOnly($f, 'b')->first_from_device)->toBeTrue();
});

it('approves: food becomes a room order at the guest\'s price on the folio with the ref, drinks go to the bar, the phone is trusted and the number saved', function () {
    $f = rmFixture();
    $request = rmSubmit($f);
    $f['jollof']->update(['sale_price' => 9999]); // a price change after the guest ordered
    $before = rmBalance($f);

    (new RoomRequestApprovalService)->approve($request, $f['receptionist'], '0814 473 4612', true);

    $order = Order::sole();
    expect($order->destination)->toBe('kitchen');
    expect($order->booking_id)->toBe($f['stay']->id);
    expect($order->status)->toBe('pending');
    expect((float) $order->items()->sole()->unit_price)->toBe(4500.0);
    expect($order->stock_deducted_at)->toBeNull(); // food stock leaves at Mark Ready
    expect(rmRice($f))->toBe(10.0);

    $charge = FolioLine::where('order_id', $order->id)->sole();
    expect($charge->description)->toBe("Room order ({$order->order_number}) · {$request->ref}");
    expect(rmBalance($f))->toBe($before + 4500.0);

    expect($request->fresh()->status)->toBe('confirmed');
    expect($request->fresh()->confirmed_by_user_id)->toBe($f['receptionist']->id);
    expect(GuestRequestItem::where('station', 'kitchen')->sole()->status)->toBe('ordered');
    expect(GuestRequestItem::where('station', 'bar')->sole()->status)->toBe('at_bar');

    expect(GuestTrustedDevice::sole()->only(['stay_id', 'device_id', 'first_approved_request_id']))
        ->toBe(['stay_id' => $f['stay']->id, 'device_id' => rmDevice(), 'first_approved_request_id' => $request->id]);
    $contact = GuestContact::sole();
    expect($contact->phone)->toBe('2348144734612');
    expect($contact->marketing_opt_in)->toBeTrue();
    expect($contact->recorded_by_user_id)->toBe($f['receptionist']->id);

    // Existing room-order callers still get exactly the old description.
    $staffOrders = (new RoomOrderService)->placeOrder($f['room']->id, ['menu_'.$f['jollof']->id => ['name' => 'Jollof Rice', 'price' => 1, 'quantity' => 1]], $f['receptionist']->id);
    expect(FolioLine::where('order_id', $staffOrders[0]->id)->value('description'))->toBe("Room order ({$staffOrders[0]->order_number})");
    expect((float) $staffOrders[0]->total_amount)->toBe(9999.0);
});

it('refuses to approve once the guest has checked out, changing nothing', function () {
    $f = rmFixture();
    $request = rmSubmit($f);
    Booking::whereKey($f['stay']->id)->update(['status' => 'checked_out']); // bypasses the checkout hook on purpose

    expect(fn () => (new RoomRequestApprovalService)->approve($request, $f['receptionist']))
        ->toThrow(Exception::class, 'no longer checked in');

    expect($request->fresh()->status)->toBe('pending');
    expect(Order::count())->toBe(0);
    expect(GuestTrustedDevice::count())->toBe(0);
    expect(GuestRequestItem::pluck('status')->unique()->all())->toBe(['pending']);
});

it('lets reception reduce or remove a line with a reason before approval, and never add (D29)', function () {
    $f = rmFixture();
    $request = rmBeerOnly($f, 'a', 3);
    $line = $request->items()->sole();
    $approvals = new RoomRequestApprovalService;

    expect(fn () => $approvals->reduce($line, 4, $f['receptionist'], 'Out of stock'))->toThrow(Exception::class, 'between 1 and 2');
    expect(fn () => $approvals->reduce($line, 3, $f['receptionist'], 'Out of stock'))->toThrow(Exception::class);
    expect(fn () => $approvals->reduce($line, 2, $f['receptionist'], 'Because'))->toThrow(Exception::class, 'Pick a reason');

    $approvals->reduce($line, 2, $f['receptionist'], 'Out of stock');
    expect($line->fresh()->quantity_final)->toBe(2);
    expect($line->fresh()->removed_reason)->toBe('Reduced to 2: Out of stock');

    $approvals->remove($line, $f['receptionist'], 'Other', 'Guest asked by phone');
    expect($line->fresh()->status)->toBe('removed');
    expect($request->fresh()->status)->toBe('cancelled_by_staff');

    // A waiter can't touch a room order at all.
    $other = rmBeerOnly($f, 'b');
    $waiter = rmUser('Emeka Waiter', 'waiter');
    expect(fn () => $approvals->remove($other->items()->sole(), $waiter, 'Out of stock'))->toThrow(Exception::class, 'Only reception');
    expect(fn () => (new GuestRequestService)->confirm($other, $waiter))->toThrow(Exception::class, 'approved by reception');
});

it('releases room drinks as a room order marked ready in one go: stock once, one charge with the ref (D24)', function () {
    $f = rmFixture();
    $before = rmBalance($f);
    $request = rmReleasedBeer($f);

    $order = Order::sole();
    expect($order->destination)->toBe('bar');
    expect($order->booking_id)->toBe($f['stay']->id);
    expect($order->status)->toBe('ready');
    expect(rmBeer($f))->toBe(18.0);
    expect(InventoryTransaction::where('reference', "order:{$order->id}")->count())->toBeGreaterThan(0);

    $charge = FolioLine::where('order_id', $order->id)->sole();
    expect($charge->description)->toEndWith(' · '.$request->ref);
    expect(rmBalance($f))->toBe($before + 2000.0);

    $line = $request->items->sole();
    expect($line->status)->toBe('released');
    expect($line->released_by_user_id)->toBe($f['bartender']->id);
    expect($line->delivery_status)->toBe('awaiting_dispatch');
    expect($order->user_id)->toBe($f['receptionist']->id);

    // A second tap changes nothing: no second order, stock or charge.
    expect(fn () => (new GuestBarReleaseService)->markReady($request))->toThrow(Exception::class);
    expect(rmBeer($f))->toBe(18.0);
    expect(Order::count())->toBe(1);
    expect(FolioLine::where('type', 'order')->count())->toBe(1);
});

it('queues room food for a porter when the kitchen marks it Ready, by listening — KitchenOrderService untouched', function () {
    $f = rmFixture();
    $request = rmSubmit($f, 'a', [['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1]]);
    (new RoomRequestApprovalService)->approve($request, $f['receptionist']);
    $line = $request->items()->sole();
    expect($line->delivery_status)->toBeNull();

    (new KitchenOrderService)->markReady(Order::sole()->id, $f['chef']->id);

    expect($line->fresh()->delivery_status)->toBe('awaiting_dispatch');
    expect(rmRice($f))->toBe(8.0);

    $source = file_get_contents(app_path('Services/KitchenOrderService.php'));
    expect(str_contains($source, 'Guest'))->toBeFalse();
    expect(str_contains($source, 'delivery_status'))->toBeFalse();
});

it('sends with a porter — the guest sees "On the way with Musa" — then marks it delivered and the order served', function () {
    $f = rmFixture();
    $request = rmReleasedBeer($f);
    $line = $request->items->sole();
    $deliveries = new RoomDeliveryService;

    expect(fn () => $deliveries->dispatch([$line->id], $f['manager'], $f['receptionist']))->toThrow(Exception::class, "isn't an active porter");

    $deliveries->dispatch([$line->id], $f['porter'], $f['receptionist']);
    expect($line->fresh()->delivery_status)->toBe('out_for_delivery');
    expect(Order::sole()->picked_up_by)->toBe($f['porter']->id);
    expect(rmGet($this, $f, '/requests')->json('requests.0.lines.0.status_label'))->toBe('On the way with Musa');

    $deliveries->delivered([$line->id], $f['receptionist']);
    expect($line->fresh()->delivery_status)->toBe('delivered');
    expect($line->fresh()->delivered_at)->not->toBeNull();
    expect(Order::sole()->status)->toBe('served');
    expect(rmGet($this, $f, '/requests')->json('requests.0.lines.0.status_label'))->toBe('Delivered');

    // The old porter page never shows a guest order, and can't act on one.
    expect(fn () => (new \App\Services\PorterDeliveryService)->confirmDelivered(Order::sole(), $f['porter']))->toThrow(Exception::class, 'Room Orders');
});

it('refused drinks wait for the bar, then a manager approves: charge reversed and bottles restocked (D26)', function () {
    $f = rmFixture();
    $before = rmBalance($f);
    $request = rmReleasedBeer($f);
    $line = $request->items->sole();
    $deliveries = new RoomDeliveryService;
    $deliveries->dispatch([$line->id], null, $f['receptionist']);

    [$refusal] = $deliveries->refused([$line->id], 'Wrong drink', $f['receptionist']);
    expect($refusal->status)->toBe('awaiting_bar_return');
    expect($refusal->station)->toBe('bar');
    expect(rmBalance($f))->toBe($before + 2000.0); // nothing reversed yet
    expect(rmGet($this, $f, '/requests')->json('requests.0.lines.0.status_label'))->toBe('Refused — being reviewed');

    expect(fn () => $deliveries->decide($refusal, $f['manager'], true))->toThrow(Exception::class, 'hasn\'t confirmed');

    $this->seed(PagePermissionsSeeder::class);
    Livewire::actingAs($f['bartender'])->test(\App\Filament\Pages\BarDisplay::class)->assertSee('Returned from rooms');
    $deliveries->confirmBarReturn($refusal, $f['bartender']);
    expect(fn () => $deliveries->decide($refusal->fresh(), $f['receptionist'], true))->toThrow(Exception::class, 'Only a manager');

    $deliveries->decide($refusal->fresh(), $f['manager'], true, 'Checked with the guest');

    expect($refusal->fresh()->status)->toBe('approved');
    expect(Order::sole()->status)->toBe('cancelled');
    expect(rmBalance($f))->toBe($before);
    expect(rmBeer($f))->toBe(20.0);
    expect(fn () => $refusal->fresh()->delete())->toThrow(LogicException::class);
});

it('refused food goes to a manager: approval reverses the charge and records waste with no restock; rejection lets the charge stand', function () {
    $f = rmFixture();
    $before = rmBalance($f);
    $deliveries = new RoomDeliveryService;

    $deliver = function (string $device) use ($f, $deliveries) {
        $request = rmSubmit($f, $device, [['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1]]);
        (new RoomRequestApprovalService)->approve($request, $f['receptionist']);
        $order = Order::where('booking_id', $f['stay']->id)->latest('id')->first();
        (new KitchenOrderService)->markReady($order->id, $f['chef']->id);
        $line = $request->items()->sole();
        $deliveries->dispatch([$line->id], $f['porter'], $f['receptionist']);

        return [$deliveries->refused([$line->id], 'Cold food', $f['receptionist'])->sole(), $line, $order];
    };

    [$approved, , $approvedOrder] = $deliver('a');
    expect($approved->status)->toBe('awaiting_manager');
    $deliveries->decide($approved, $f['manager'], true);

    expect($approvedOrder->fresh()->status)->toBe('cancelled');
    expect(KitchenWasteLog::where('order_id', $approvedOrder->id)->exists())->toBeTrue();
    expect(rmRice($f))->toBe(8.0); // cooked: never restocked

    [$rejected, $rejectedLine, $rejectedOrder] = $deliver('b');
    $deliveries->decide($rejected, $f['manager'], false, 'Guest ate half');

    expect($rejected->fresh()->status)->toBe('rejected');
    expect($rejectedOrder->fresh()->status)->toBe('served');
    expect($rejectedLine->fresh()->delivery_status)->toBe('delivered');
    expect(rmBalance($f))->toBe($before + 4500.0); // only the rejected one stays charged
});

it('shows a room bill only to a phone reception has trusted, and only this stay\'s lines (D25)', function () {
    $f = rmFixture();

    // A previous stay in the same room, with its own order, paid and checked out.
    $old = Booking::sole();
    (new RoomOrderService)->placeOrder($f['room']->id, ['menu_'.$f['jollof']->id => ['name' => 'Jollof Rice', 'price' => 1, 'quantity' => 1]], $f['receptionist']->id);
    (new FolioService)->recordPayment($old->folio, $old->folio->balance(), 'cash', null, $f['receptionist']->id);
    (new BookingService)->checkOut($old, $f['receptionist']->id);
    $f['stay'] = rmStay($f['room'], $f['receptionist'], 'Bola Ade');

    $request = rmBeerOnly($f, 'a');

    rmGet($this, $f, '/bill', 'a')->assertOk()
        ->assertJson(['bill' => null, 'message' => 'Your bill appears after your first order is approved.', 'table' => ['state' => 'room', 'trusted' => false]]);

    (new RoomRequestApprovalService)->approve($request, $f['receptionist']);
    (new GuestBarReleaseService)->markReady($request);

    $bill = rmGet($this, $f, '/bill', 'a')->assertOk()->json('bill');
    $names = collect($bill['sections']['on_bill'])->pluck('name')->all();
    expect($names)->toContain('Star Beer');
    expect($names)->not->toContain('Jollof Rice'); // the previous stay's
    expect(collect($bill['sections']['on_bill'])->firstWhere('name', 'Star Beer')['status_label'])->toBe('Ready');

    // Another phone in the same room still sees nothing.
    rmGet($this, $f, '/bill', 'z')->assertJson(['bill' => null]);
    $json = rmGet($this, $f, '/bill', 'a')->getContent();
    expect(str_contains($json, Order::where('booking_id', $f['stay']->id)->value('order_number')))->toBeFalse();
});

it('shows room claims to reception; "Open folio payment" records it on the folio and matches the claim; "Not received" unmatches', function () {
    $f = rmFixture();
    $this->seed(PagePermissionsSeeder::class);
    $request = rmBeerOnly($f);
    (new RoomRequestApprovalService)->approve($request, $f['receptionist']);
    (new GuestBarReleaseService)->markReady($request);

    // Untrusted phone: refused.
    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice('z'))
        ->postJson('/m/'.$f['room']->qr_token.'/claims', ['payer_name' => 'Zed', 'amount' => 100])->assertStatus(403);

    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice('a'))
        ->postJson('/m/'.$f['room']->qr_token.'/claims', ['payer_name' => 'Ada Eze', 'amount' => 1500])->assertCreated();
    (new GuestClaimService)->createForStay($f['stay'], rmDevice('a'), 'Ada Eze', 500);
    [$first, $second] = GuestPaymentClaim::orderBy('id')->get()->all();
    expect($first->stay_id)->toBe($f['stay']->id);
    expect($first->guest_table_session_id)->toBeNull();

    $balance = rmBalance($f);

    Livewire::actingAs($f['receptionist'])->test(RoomOrders::class)
        ->assertSee('Ada Eze says they paid ₦1,500')
        ->call('settleClaim', $first->id, 1500.0, 'Ada Eze')
        ->assertNotified('Payment recorded on the folio')
        ->call('claimNotReceived', $second->id);

    $payment = FolioLine::where('type', 'payment')->latest('id')->first();
    expect($payment->payment_method)->toBe('transfer');
    expect($payment->reference)->toBe('Ada Eze');
    expect((float) $payment->amount)->toBe(-1500.0);
    expect(rmBalance($f))->toBe($balance - 1500.0);
    expect($first->fresh()->status)->toBe('matched');
    expect($second->fresh()->status)->toBe('unmatched');
});

it('cancels pending and at-bar room requests at checkout ("checked_out")', function () {
    $f = rmFixture();
    $pending = rmBeerOnly($f, 'a');
    $atBar = rmBeerOnly($f, 'b');
    (new RoomRequestApprovalService)->approve($atBar, $f['receptionist']);

    $folio = $f['stay']->fresh()->folio;
    (new FolioService)->recordPayment($folio, $folio->balance(), 'cash', null, $f['receptionist']->id);
    (new BookingService)->checkOut($f['stay']->fresh(), $f['receptionist']->id);

    expect($pending->fresh()->status)->toBe('cancelled_by_staff');
    expect($pending->fresh()->cancel_reason)->toBe('checked_out');
    expect($atBar->items()->sole()->status)->toBe('cancelled');
    expect($atBar->items()->sole()->removed_reason)->toBe('checked_out');
    expect($atBar->fresh()->status)->toBe('cancelled_by_staff');
    expect(Order::count())->toBe(0);
});

it('sends room calls to reception with Cutlery, and leaves the table flow unchanged', function () {
    $f = rmFixture();
    $this->seed(PagePermissionsSeeder::class);

    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice())
        ->postJson('/m/'.$f['room']->qr_token.'/calls', ['reason' => 'cutlery'])->assertCreated();
    $call = GuestWaiterCall::sole();
    expect($call->room_id)->toBe($f['room']->id);
    expect($call->table_id)->toBeNull();

    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice('b'))
        ->postJson('/m/'.$f['room']->qr_token.'/calls', ['reason' => 'bill'])->assertStatus(422)->assertJson(['code' => 'bad_reason']);
    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice('c'))
        ->postJson('/m/'.$f['empty']->qr_token.'/calls', ['reason' => 'ice'])->assertStatus(422);

    Livewire::actingAs($f['receptionist'])->test(RoomOrders::class)
        ->assertSee('Room 7 · Cutlery')
        ->call('acknowledgeCall', $call->id)
        ->assertNotified('Room 7 · on it');
    expect($call->fresh()->status)->toBe('acknowledged');
    expect($call->fresh()->acknowledged_by_user_id)->toBe($f['receptionist']->id);

    // Tables: Cutlery isn't theirs, Bill still is; the waiter strip never shows room calls.
    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice('d'))
        ->postJson('/m/'.$table->qr_token.'/calls', ['reason' => 'cutlery'])->assertStatus(422);
    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice('e'))
        ->postJson('/m/'.$table->qr_token.'/calls', ['reason' => 'bill'])->assertCreated();
    $this->withCredentials()->withUnencryptedCookie('selum_gd', rmDevice('f'))
        ->postJson('/m/'.$f['room']->qr_token.'/calls', ['reason' => 'ice'])->assertCreated();
    Livewire::test('guest-orders-strip')->assertSee('Table 5 · Bill')->assertDontSee('Room 7');
});

it('opens Room Orders to reception and managers only; Guest Contacts to managers only, exporting opted-in rows only', function () {
    $f = rmFixture();
    $this->seed(PagePermissionsSeeder::class);
    $this->seed(ShieldSeeder::class);
    $waiter = rmUser('Emeka Waiter', 'waiter');

    $this->actingAs($f['receptionist'])->get(RoomOrders::getUrl())->assertOk();
    $this->actingAs($f['manager'])->get(RoomOrders::getUrl())->assertOk();
    $this->actingAs($waiter)->get(RoomOrders::getUrl())->assertForbidden();

    // The page refuses a waiter at mount (403 above); the service refuses one too.
    $request = rmBeerOnly($f);
    expect(fn () => (new RoomRequestApprovalService)->approve($request, $waiter))->toThrow(Exception::class, 'Only reception');
    expect($request->fresh()->status)->toBe('pending');

    (new RoomRequestApprovalService)->approve($request, $f['receptionist'], '08031112222', false);
    (new RoomRequestApprovalService)->approve(rmBeerOnly($f, 'b'), $f['receptionist'], '+234 905 333 4444', true);

    $this->actingAs($f['manager'])->get(GuestContactResource::getUrl())->assertOk()->assertSee('2348031112222')->assertSee('2349053334444');
    $this->actingAs($f['receptionist'])->get(GuestContactResource::getUrl())->assertForbidden();

    expect(array_column(GuestContactResource::exportRows(), 0))->toBe(['2349053334444']);

    // Turning specials on later is allowed (and logged); turning off is not.
    $contact = GuestContact::where('phone', '2348031112222')->sole();
    $contact->optIn($f['manager']);
    expect($contact->fresh()->marketing_opt_in)->toBeTrue();
    expect(fn () => $contact->fresh()->update(['marketing_opt_in' => false]))->toThrow(LogicException::class);
    expect(fn () => $contact->fresh()->delete())->toThrow(LogicException::class);
    expect(collect(GuestContactResource::exportRows())->pluck(0)->sort()->values()->all())->toBe(['2348031112222', '2349053334444']);
});

it('lets the manager decide a refusal from the Delivery Refusals resource', function () {
    $f = rmFixture();
    $this->seed(ShieldSeeder::class);
    $request = rmReleasedBeer($f);
    $deliveries = new RoomDeliveryService;
    $deliveries->dispatch($request->items->pluck('id')->all(), null, $f['receptionist']);
    $refusal = $deliveries->refused($request->items->pluck('id')->all(), 'Wrong room', $f['receptionist'])->sole();
    $deliveries->confirmBarReturn($refusal, $f['bartender']);

    $this->actingAs($f['manager'])->get(\App\Filament\Resources\GuestDeliveryRefusals\GuestDeliveryRefusalResource::getUrl())->assertOk()->assertSee('Wrong room');
    $this->actingAs($f['receptionist'])->get(\App\Filament\Resources\GuestDeliveryRefusals\GuestDeliveryRefusalResource::getUrl())->assertForbidden();

    Livewire::actingAs($f['manager'])
        ->test(\App\Filament\Resources\GuestDeliveryRefusals\Pages\ListGuestDeliveryRefusals::class)
        ->callTableAction('approve', $refusal, ['note' => 'OK'])
        ->assertNotified('Refusal approved — charge reversed');

    expect($refusal->fresh()->status)->toBe('approved');
});

it('lets another round load a room\'s last released drinks, scoped to the stay and phone', function () {
    $f = rmFixture();
    rmReleasedBeer($f, 'a');

    $round = rmGet($this, $f, '/round', 'a')->assertOk()->json();
    expect($round['lines'])->toHaveCount(1);
    expect($round['lines'][0])->toMatchArray(['key' => 'p'.$f['beer']->id, 'qty' => 2, 'chips' => ['Cold']]);
    expect(rmGet($this, $f, '/round', 'b')->json('lines'))->toBe([]);
});
