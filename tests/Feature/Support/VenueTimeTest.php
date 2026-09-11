<?php

use App\Models\Category;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\WareHouse;
use App\Support\BusinessDay;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Timestamps are stored in UTC and were displayed in UTC too — nothing
 * converted them on the way to the screen — so every date and time in the
 * app read an hour early for a Lagos user, and anything that happened
 * between midnight and 1am Lagos showed under the previous date.
 *
 * The stored data was never wrong, which is why this fix is display-only:
 * converting on the way out makes the entire existing history read
 * correctly, with no data migration and no risk of shifting good rows.
 */
it('keeps storing timestamps in UTC — the fix is display-only, so no stored row needs changing', function () {
    expect(config('app.timezone'))->toBe('UTC');
    expect(now()->getTimezone()->getName())->toBe('UTC');
});

it('renders a stored UTC instant as venue wall-clock time', function () {
    $stored = CarbonImmutable::parse('2026-07-11 00:08:39', 'UTC');

    expect($stored->format('d M Y, g:i A'))->toBe('11 Jul 2026, 12:08 AM')
        ->and($stored->venueTime()->format('d M Y, g:i A'))->toBe('11 Jul 2026, 1:08 AM');
});

/**
 * The whole sweep depends on this: because setTimezone() on a value that
 * is already in Lagos does nothing, ->venueTime() can be applied at a
 * display site without first proving the value was still UTC. A value
 * that has already been converted cannot be shifted a second time.
 */
it('is idempotent, so a value already converted is never shifted twice', function () {
    $stored = CarbonImmutable::parse('2026-07-11 00:08:39', 'UTC');

    expect($stored->venueTime()->venueTime()->venueTime()->format('d M Y, g:i A'))
        ->toBe($stored->venueTime()->format('d M Y, g:i A'));
});

it('moves a late-night instant onto the correct calendar day', function () {
    // 23:30 UTC is already 00:30 the NEXT day in Lagos — the case that made
    // records show up filed under the wrong date.
    $stored = CarbonImmutable::parse('2026-07-11 23:30:00', 'UTC');

    expect($stored->format('d M Y'))->toBe('11 Jul 2026')
        ->and($stored->venueTime()->format('d M Y'))->toBe('12 Jul 2026');
});

it('leaves the whole Filament layer on venue time in both panels', function () {
    expect(FilamentTimezone::get())->toBe('Africa/Lagos')
        ->and(VenueTime::TIMEZONE)->toBe(BusinessDay::TIMEZONE);
});

it('formats and converts null safely so nullable columns need no guard at the call site', function () {
    expect(VenueTime::convert(null))->toBeNull()
        ->and(VenueTime::format(null))->toBe('—')
        ->and(VenueTime::format(null, VenueTime::DATE_FORMAT, 'n/a'))->toBe('n/a');
});

/**
 * The user's actual worry: that historical rows were stored wrong and
 * would need correcting. They were not — so an old transfer, written long
 * before any of this, reads correctly the moment the screen converts.
 */
it('shows a months-old transfer at its correct venue time with no data migration', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create([
        'user_id' => $bartender->id, 'type' => 'bartender',
        'started_at' => now()->subHours(2), 'status' => 'active',
    ]);

    PagePermission::firstOrCreate(
        ['page_class' => \App\Filament\Pages\ReceiveTransfers::class, 'role_name' => 'bartender'],
        ['page_class' => \App\Filament\Pages\ReceiveTransfers::class, 'page_name' => 'Receive Transfers', 'role_name' => 'bartender']
    );

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);

    // Written months ago, in UTC, exactly as the app has always stored it.
    $sentUtc = CarbonImmutable::parse('2026-03-02 23:40:00', 'UTC');
    $receivedUtc = CarbonImmutable::parse('2026-03-03 00:15:00', 'UTC');

    $transfer = StockTransfer::create([
        'transfer_number' => 'ST-OLD-1', 'from_warehouse_id' => $main->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'received',
    ]);
    $transfer->forceFill(['created_at' => $sentUtc])->save();

    StockTransferItem::create([
        'stock_transfer_id' => $transfer->id, 'product_id' => $beer->id,
        'quantity' => 10, 'received_quantity' => 10, 'outcome' => 'received_full',
        'received_by' => $bartender->id, 'received_at' => $receivedUtc,
    ]);

    $html = Livewire::actingAs($bartender)
        ->test(\App\Filament\Pages\ReceiveTransfers::class)
        ->call('load')
        ->html();

    // Sent 23:40 UTC = 00:40 on the 3rd in Lagos; received 00:15 UTC =
    // 01:15 on the 3rd. Both roll onto a different day than the raw
    // stored value would have shown.
    expect($html)->toContain('03 Mar 2026, 12:40 AM')
        ->and($html)->toContain('03 Mar 2026, 1:15 AM')
        ->and($html)->not->toContain('02 Mar 2026, 11:40 PM');
});
