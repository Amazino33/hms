<?php

use App\Filament\Pages\BarDisplay;
use App\Filament\Pages\FolioDetail;
use App\Models\Category;
use App\Models\FolioLine;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\KitchenWasteLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\KitchenOrderService;
use App\Services\ReservationService;
use App\Services\RoomOrderService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 0C — a room order can be cancelled, and its folio charge comes off
 * by an append-only reversal line. See docs/audits/room-cancel-verification.md.
 */
function rcUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => $role]));

    return $user;
}

function rcFixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $food = Category::create(['name' => 'Food', 'type' => 'food']);

    $malt = Product::create(['name' => 'Malta', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $malt->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);

    $jollof = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-RC-'.uniqid(), 'sale_price' => 4500, 'category_id' => $food->id, 'available_for_sale' => true]);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RC-'.uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 300, 'category' => 'Grains']);
    Recipe::create(['menu_item_id' => $jollof->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 2]);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);

    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);
    $bartender = rcUser('super_admin');
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $room = Room::create(['number' => '7'.random_int(10, 99), 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = rcUser('receptionist');
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Cancel Guest', 'guest_phone' => '0806'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    $booking = (new BookingService)->checkIn($booking, $receptionist->id);

    return compact('bar', 'kitchen', 'malt', 'jollof', 'rice', 'chef', 'bartender', 'room', 'receptionist', 'booking');
}

/** 1x Jollof (kitchen) + 2x Malta (bar) to the room. */
function rcPlace(array $f): array
{
    $orders = collect((new RoomOrderService)->placeOrder($f['room']->id, [
        'menu_'.$f['jollof']->id => ['name' => 'Jollof Rice', 'price' => 4500, 'quantity' => 1],
        (string) $f['malt']->id => ['name' => 'Malta', 'price' => 1000, 'quantity' => 2],
    ], $f['receptionist']->id));

    return [$orders->firstWhere('destination', 'kitchen'), $orders->firstWhere('destination', 'bar')];
}

function rcBalance(array $f): float
{
    return $f['booking']->folio->fresh()->balance();
}

function rcRice(array $f): float
{
    return (float) IngredientInventoryItem::where('ingredient_id', $f['rice']->id)->where('warehouse_id', $f['kitchen']->id)->value('quantity');
}

function rcMalt(array $f): float
{
    return (float) InventoryItem::where('product_id', $f['malt']->id)->where('warehouse_id', $f['bar']->id)->value('quantity');
}

it('posts one folio charge per order, each naming its order', function () {
    $f = rcFixture();
    [$food, $drinks] = rcPlace($f);

    $charges = FolioLine::where('type', 'order')->orderBy('id')->get();
    expect($charges)->toHaveCount(2);
    expect($charges->pluck('order_id')->all())->toEqualCanonicalizing([$food->id, $drinks->id]);
    expect((float) $charges->firstWhere('order_id', $food->id)->amount)->toBe(4500.0);
    expect((float) $charges->firstWhere('order_id', $drinks->id)->amount)->toBe(2000.0);
});

it('cancels before Mark Ready with a zero net folio effect and no stock movement', function () {
    $f = rcFixture();
    $balanceBefore = rcBalance($f);
    [$food] = rcPlace($f);

    (new RoomOrderService)->cancel($food, $f['receptionist'], 'Guest changed their mind');

    expect($food->fresh()->status)->toBe('cancelled');
    expect($food->fresh()->cancellation_reason)->toBe('Guest changed their mind');
    // Only the drinks are still owed.
    expect(rcBalance($f))->toBe($balanceBefore + 2000.0);
    expect(FolioLine::where('order_id', $food->id)->sum('amount'))->toEqual(0);
    expect(IngredientTransaction::count())->toBe(0);
    expect(InventoryTransaction::count())->toBe(0);
    expect(rcRice($f))->toBe(10.0);
    expect(KitchenWasteLog::count())->toBe(0);
});

it('cancels cooked food after Mark Ready: charge reversed, waste recorded, never restocked', function () {
    $f = rcFixture();
    [$food] = rcPlace($f);
    (new KitchenOrderService)->markReady($food->id, $f['chef']->id);
    expect(rcRice($f))->toBe(8.0);

    $manager = rcUser('manager');
    $this->actingAs($manager);
    (new RoomOrderService)->cancel($food, $manager, 'Guest asleep, food went cold');

    expect(FolioLine::where('order_id', $food->id)->sum('amount'))->toEqual(0);
    expect(rcRice($f))->toBe(8.0);
    expect(IngredientTransaction::where('type', 'return')->exists())->toBeFalse();

    $waste = KitchenWasteLog::sole();
    expect($waste->order_id)->toBe($food->id);
    expect($waste->reason)->toBe('Guest asleep, food went cold');
    expect($waste->order_status_before)->toBe('ready');
});

it('handles a room drink exactly like the bar void: nothing back before Mark Ready, restocked after', function () {
    $f = rcFixture();

    // Before bar Mark Ready: nothing ever left the shelf, nothing comes back.
    [, $drinksA] = rcPlace($f);
    (new RoomOrderService)->cancel($drinksA, $f['receptionist'], 'Wrong room');
    expect(rcMalt($f))->toBe(20.0);
    expect(InventoryTransaction::count())->toBe(0);

    // After bar Mark Ready: the two Maltas left at Mark Ready and go back.
    $this->travel(2)->seconds();
    [, $drinksB] = rcPlace($f);
    Livewire::actingAs($f['bartender'])->test(BarDisplay::class)->call('markAsReady', $drinksB->id);
    expect(rcMalt($f))->toBe(18.0);

    $manager = rcUser('manager');
    (new RoomOrderService)->cancel($drinksB, $manager, 'Guest refused, unopened');

    expect(rcMalt($f))->toBe(20.0);
    expect(InventoryTransaction::where('reference', "order:{$drinksB->id}")->where('type', 'return')->sum('quantity'))->toEqual(2);
    expect(KitchenWasteLog::count())->toBe(0);
});

it('refuses a second cancel or a second reversal of the same charge', function () {
    $f = rcFixture();
    [$food] = rcPlace($f);
    $service = new RoomOrderService;

    $service->cancel($food, $f['receptionist'], 'Guest changed their mind');

    expect(fn () => $service->cancel($food->fresh(), $f['receptionist'], 'Again'))
        ->toThrow(Exception::class, 'already cancelled');

    $charge = FolioLine::where('order_id', $food->id)->whereNull('reversal_of_line_id')->sole();
    expect(fn () => (new \App\Services\FolioService)->reverseOrderCharge($charge, 'Again', $f['receptionist']->id))
        ->toThrow(Exception::class, 'already been reversed');

    expect(FolioLine::where('reversal_of_line_id', $charge->id)->count())->toBe(1);
});

it('rejects an unauthorised role server-side, and only managers can cancel once the order is made', function () {
    $f = rcFixture();
    [$food, $drinks] = rcPlace($f);
    $service = new RoomOrderService;

    expect(fn () => $service->cancel($food, rcUser('waiter'), 'No'))->toThrow(Exception::class, 'Only reception or a manager');
    expect(fn () => $service->cancel($food, rcUser('cashier'), 'No'))->toThrow(Exception::class, 'Only reception or a manager');

    (new KitchenOrderService)->markReady($food->id, $f['chef']->id);
    expect(fn () => $service->cancel($food->fresh(), $f['receptionist'], 'No'))->toThrow(Exception::class, 'Only a manager can cancel it now');

    expect($food->fresh()->status)->toBe('ready');
    expect($drinks->fresh()->status)->toBe('pending');
    expect(FolioLine::whereNotNull('reversal_of_line_id')->count())->toBe(0);
});

it('requires a reason', function () {
    $f = rcFixture();
    [$food] = rcPlace($f);

    expect(fn () => (new RoomOrderService)->cancel($food, $f['receptionist'], '   '))->toThrow(Exception::class, 'reason is required');
    expect($food->fresh()->status)->toBe('pending');
});

it('lets the guest check out when the only room-order charges were fully reversed', function () {
    $f = rcFixture();
    // Settle the room charge so the room orders are the only thing in play.
    (new \App\Services\FolioService)->recordPayment($f['booking']->folio, rcBalance($f), 'cash', null, $f['receptionist']->id);
    expect(rcBalance($f))->toBe(0.0);

    [$food, $drinks] = rcPlace($f);
    expect(rcBalance($f))->toBe(6500.0);

    (new RoomOrderService)->cancel($food, $f['receptionist'], 'Changed mind');
    (new RoomOrderService)->cancel($drinks, $f['receptionist'], 'Changed mind');

    expect(rcBalance($f))->toBe(0.0);
    $booking = (new BookingService)->checkOut($f['booking']->fresh(), $f['receptionist']->id);
    expect($booking->status)->toBe('checked_out');
});

it('leaves the original charge row byte-for-byte unchanged', function () {
    $f = rcFixture();
    [$food] = rcPlace($f);
    $charge = FolioLine::where('order_id', $food->id)->sole();
    $before = $charge->getRawOriginal();

    $this->travel(5)->minutes();
    (new RoomOrderService)->cancel($food, $f['receptionist'], 'Changed mind');

    expect($charge->fresh()->getRawOriginal())->toBe($before);

    $reversal = FolioLine::where('reversal_of_line_id', $charge->id)->sole();
    expect((float) $reversal->amount)->toBe(-4500.0);
    expect($reversal->order_id)->toBe($food->id);
    expect($reversal->created_by)->toBe($f['receptionist']->id);
    expect($reversal->description)->toContain('Changed mind');
});

it('refuses to edit or delete a folio line at the model level', function () {
    $f = rcFixture();
    [$food] = rcPlace($f);
    $charge = FolioLine::where('order_id', $food->id)->sole();

    expect(fn () => $charge->update(['amount' => 1]))->toThrow(LogicException::class);
    expect(fn () => $charge->delete())->toThrow(LogicException::class);
    expect((float) $charge->fresh()->amount)->toBe(4500.0);
});

it('cancels every order named in an older combined charge together', function () {
    $f = rcFixture();
    [$food, $drinks] = rcPlace($f);

    // Rebuild what a pre-Phase-0C room order looked like: one line, no
    // order_id, both order numbers in the description. (Seeded through the
    // query builder — the model itself refuses to rewrite folio lines.)
    \Illuminate\Support\Facades\DB::table('folio_lines')->where('type', 'order')->delete();
    $legacy = FolioLine::create([
        'folio_id' => $f['booking']->folio->id, 'type' => 'order', 'amount' => 6500,
        'description' => "Room order ({$food->order_number}, {$drinks->order_number})", 'created_by' => $f['receptionist']->id,
    ]);

    $cancelled = (new RoomOrderService)->cancel($drinks, $f['receptionist'], 'Guest left early');

    expect($cancelled->pluck('id')->all())->toEqualCanonicalizing([$food->id, $drinks->id]);
    expect($food->fresh()->status)->toBe('cancelled');
    expect($drinks->fresh()->status)->toBe('cancelled');
    expect((float) FolioLine::where('reversal_of_line_id', $legacy->id)->sole()->amount)->toBe(-6500.0);
});

it('lets reception cancel from the folio page, with a required reason', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $f = rcFixture();
    [$food] = rcPlace($f);
    $admin = rcUser('super_admin');

    $page = Livewire::actingAs($admin)
        ->withQueryParams(['booking' => $f['booking']->id])
        ->test(FolioDetail::class)
        ->assertSee('Cancel room order');

    $page->call('openCancelRoomOrder', $food->id)
        ->call('cancelRoomOrder')
        ->assertNotified('A reason is required');
    expect($food->fresh()->status)->toBe('pending');

    $page->set('cancelOrderReason', 'Guest changed their mind')
        ->call('cancelRoomOrder')
        ->assertNotified('Room order cancelled');

    expect($food->fresh()->status)->toBe('cancelled');
    expect(FolioLine::where('order_id', $food->id)->sum('amount'))->toEqual(0);
});
