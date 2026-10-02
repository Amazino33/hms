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
 * Phase 7A is UI only: every guest JSON endpoint must keep exactly the
 * shape it had before the refresh. The shapes (keys and value types, never
 * values) were recorded from the Phase 5 code into
 * tests/Feature/Guest/snapshots/guest-json-shapes.json; this test compares
 * against them. Delete that file only when an endpoint is meant to change.
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
});
