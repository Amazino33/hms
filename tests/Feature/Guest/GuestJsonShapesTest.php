<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\TransferAccount;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\RoomRequestApprovalService;
use App\Services\PinAuthService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Every guest JSON endpoint keeps its shape (keys and value types, never
 * values). Phase 7A recorded the Phase 5 shapes into
 * snapshots/guest-json-shapes-7a.json. Phase 7C may only ADD keys — the
 * bill's latest_status, accepted_by_first_name and last_round (D36/D38) —
 * so the 7A shape must still sit unchanged inside today's, and today's
 * full shape is pinned in snapshots/guest-json-shapes.json. Delete that
 * file only when an endpoint is meant to change.
 */
function gsShape(mixed $value): mixed
{
    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }

        if (array_is_list($value)) {
            return [gsShape($value[0])];
        }

        $shape = [];
        foreach ($value as $key => $item) {
            $shape[$key] = gsShape($item);
        }
        ksort($shape);

        return $shape;
    }

    return get_debug_type($value);
}

/**
 * Keys in $now that are not in $was — and fails on anything removed or
 * retyped. Paths are dotted ("table.bill.latest_status").
 *
 * @return list<string>
 */
function gsAddedKeys(mixed $was, mixed $now, string $path = ''): array
{
    if (! is_array($was) || array_is_list($was)) {
        if (is_array($was) && is_array($now) && array_is_list($now)) {
            return gsAddedKeys($was[0], $now[0], $path.'[]');
        }
        expect($now)->toBe($was, "Shape changed at {$path}");

        return [];
    }

    expect($now)->toBeArray("{$path} is no longer an object");
    $added = [];
    foreach ($was as $key => $shape) {
        expect(array_key_exists($key, $now))->toBeTrue("Key removed: {$path}.{$key}");
        $added = [...$added, ...gsAddedKeys($shape, $now[$key], ltrim("{$path}.{$key}", '.'))];
    }
    foreach (array_diff_key($now, $was) as $key => $shape) {
        $added[] = ltrim("{$path}.{$key}", '.');
    }

    return $added;
}

function gsFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Beers', 'type' => 'drink']);
    DB::table('category_chip_group')->insert([
        ['category_id' => $drinks->id, 'chip_group_id' => DB::table('chip_groups')->where('name', 'Temperature')->value('id')],
    ]);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true]);
    \App\Models\InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 50]);

    $waiter = User::factory()->create(['name' => 'Emeka Obi']);
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));
    (new PinAuthService)->setPin($waiter, '4826');
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $bartender = User::factory()->create(['name' => 'Bisi Bar']);
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    $receptionist = User::factory()->create(['name' => 'Kemi Desk']);
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));

    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $room = Room::create(['number' => '7', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Ada Eze', 'guest_phone' => '08061234567',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    (new BookingService)->checkIn($booking, $receptionist->id);
    TransferAccount::create(['bank_name' => 'GTBank', 'account_name' => 'Selum Lounge', 'account_number' => '0123456789', 'active' => true]);
    Cache::flush();

    return compact('beer', 'waiter', 'receptionist', 'table', 'room');
}

it('keeps every guest JSON endpoint in exactly its Phase 5 shape', function () {
    $f = gsFixture();
    $as = fn (string $device) => $this->withCredentials()->withUnencryptedCookie('selum_gd', str_repeat($device, 32));
    $t = '/m/'.$f['table']->qr_token;
    $r = '/m/'.$f['room']->qr_token;
    $line = [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2, 'chips' => ['Cold'], 'note' => 'no ice']];

    $shapes = [];

    // Tables
    $shapes['table.submit'] = $as('a')->postJson("$t/requests", ['lines' => $line])->json();
    $shapes['table.requests'] = $as('a')->getJson("$t/requests")->json();
    $pending = $as('a')->postJson("$t/requests", ['lines' => $line])->json('request.ref');
    $shapes['table.cancel'] = $as('a')->postJson("$t/requests/{$pending}/cancel", [])->json();
    $shapes['table.availability'] = $as('a')->getJson("$t/availability")->json();

    $request = \App\Models\GuestRequest::where('status', 'pending')->first();
    (new GuestRequestService)->confirm($request, $f['waiter']);
    (new GuestBarReleaseService)->markReady($request);
    Order::query()->update(['status' => 'served']);

    $shapes['table.bill'] = $as('a')->getJson("$t/bill")->json();
    $shapes['table.claim'] = $as('a')->postJson("$t/claims", ['payer_name' => 'Ada Eze', 'amount' => 500])->json();
    $claimId = \App\Models\GuestPaymentClaim::value('id');
    $shapes['table.withdraw'] = $as('a')->postJson("$t/claims/{$claimId}/withdraw", [])->json();
    $shapes['table.call'] = $as('a')->postJson("$t/calls", ['reason' => 'ice'])->json();
    $shapes['table.round'] = $as('a')->getJson("$t/round")->json();
    $shapes['table.accounts'] = $as('a')->getJson("$t/accounts")->json();
    $shapes['table.bill.after_claim_and_call'] = $as('a')->getJson("$t/bill")->json();

    // Rooms
    $shapes['room.submit'] = $as('r')->postJson("$r/requests", ['channel' => 'whatsapp', 'lines' => $line])->json();
    $shapes['room.bill.untrusted'] = $as('r')->getJson("$r/bill")->json();
    (new RoomRequestApprovalService)->approve(\App\Models\GuestRequest::whereNotNull('room_id')->sole(), $f['receptionist']);
    $shapes['room.bill.trusted'] = $as('r')->getJson("$r/bill")->json();
    $shapes['room.requests'] = $as('r')->getJson("$r/requests")->json();

    // Errors
    $shapes['error.invalid_code'] = $as('a')->getJson('/m/NOTAREALCODE/bill')->json();
    $shapes['error.validation'] = $as('b')->postJson("$t/requests", ['lines' => []])->json();

    $actual = array_map('gsShape', $shapes);
    $file = __DIR__.'/snapshots/guest-json-shapes.json';

    if (! is_file($file)) {
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->markTestIncomplete('Snapshot recorded — run again to compare.');
    }

    expect($actual)->toBe(json_decode(file_get_contents($file), true));

    // Phase 7C: only additive keys, and only the three on the bill.
    $added = gsAddedKeys(json_decode(file_get_contents(__DIR__.'/snapshots/guest-json-shapes-7a.json'), true), $actual);
    $billKeys = fn (string $bill) => ["{$bill}.latest_status", "{$bill}.accepted_by_first_name", "{$bill}.last_round"];
    expect($added)->toEqualCanonicalizing([
        ...$billKeys('table.bill'),
        ...$billKeys('table.bill.after_claim_and_call'),
        ...$billKeys('room.bill.untrusted'),
        ...$billKeys('room.bill.trusted'),
    ]);
});
