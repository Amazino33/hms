<?php

use App\Filament\Pages\ReceiveTransfers;
use App\Filament\Pages\StorekeeperTransfers;
use App\Models\Category;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\WareHouse;
use App\Support\VenueTime;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Transfer history carried no date at all on the bartender/chef side, and
 * only a relative "3 days ago" on the storekeeper's. Both were stored the
 * whole time (created_at on the transfer, received_at per line) — just
 * never shown. With Past Transfers listing every received transfer since
 * the beginning, ten per page, that left nobody able to say which delivery
 * a row referred to. Both screens now quote the same absolute sent and
 * received timestamps, side by side.
 */
function seedDatedTransfer(): array
{
    $storekeeper = User::factory()->create(['name' => 'Molly Storekeeper']);
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $bartender = User::factory()->create(['name' => 'Bruno Bartender']);
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create([
        'user_id' => $bartender->id, 'type' => 'bartender',
        'started_at' => now()->subHours(2), 'status' => 'active',
    ]);

    foreach ([[StorekeeperTransfers::class, 'storekeeper'], [ReceiveTransfers::class, 'bartender']] as [$page, $role]) {
        PagePermission::firstOrCreate(
            ['page_class' => $page, 'role_name' => $role],
            ['page_class' => $page, 'page_name' => 'Transfers', 'role_name' => $role]
        );
    }

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 500, 'category_id' => $drinks->id, 'is_active' => true]);

    $sentAt = now()->subDays(3)->setTime(14, 5);
    $receivedAt = now()->subDays(2)->setTime(9, 30);

    $transfer = StockTransfer::create([
        'transfer_number' => 'ST-DATE-1', 'from_warehouse_id' => $main->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'received',
    ]);
    $transfer->forceFill(['created_at' => $sentAt])->save();

    StockTransferItem::create([
        'stock_transfer_id' => $transfer->id, 'product_id' => $beer->id,
        'quantity' => 10, 'received_quantity' => 10, 'outcome' => 'received_full',
        'received_by' => $bartender->id, 'received_at' => $receivedAt,
    ]);

    return [
        'storekeeper' => $storekeeper,
        'bartender' => $bartender,
        // Built through VenueTime, not raw ->format(), because the screens
        // now render venue wall-clock while the rows stay stored in UTC.
        'sentLabel' => VenueTime::convert($sentAt)->format(StockTransfer::DISPLAY_DATE_FORMAT),
        'receivedLabel' => VenueTime::convert($receivedAt)->format(StockTransfer::DISPLAY_DATE_FORMAT),
    ];
}

it('shows both the sent and received dates on the bartender Past Transfers history', function () {
    ['bartender' => $bartender, 'sentLabel' => $sentLabel, 'receivedLabel' => $receivedLabel] = seedDatedTransfer();

    $html = Livewire::actingAs($bartender)
        ->test(ReceiveTransfers::class)
        ->call('load')
        ->html();

    expect($html)->toContain($sentLabel);
    expect($html)->toContain($receivedLabel);
});

it('shows both dates on the storekeeper Recent Transfers history too', function () {
    ['storekeeper' => $storekeeper, 'sentLabel' => $sentLabel, 'receivedLabel' => $receivedLabel] = seedDatedTransfer();

    $html = Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->html();

    expect($html)->toContain($sentLabel);
    expect($html)->toContain($receivedLabel);
});

/**
 * "3 days ago" is fine for glancing at today's queue and useless for
 * settling an argument about one specific delivery, which is the job this
 * history actually has.
 */
it('no longer falls back to a relative timestamp on the storekeeper history', function () {
    ['storekeeper' => $storekeeper] = seedDatedTransfer();

    $html = Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->html();

    expect($html)->not->toContain('days ago');
});

it('reports the last line as the received date when a transfer was received in stages', function () {
    ['bartender' => $bartender] = seedDatedTransfer();

    $transfer = StockTransfer::firstOrFail();
    $beer = Product::firstOrFail();

    $later = now()->subDay()->setTime(16, 45);
    StockTransferItem::create([
        'stock_transfer_id' => $transfer->id, 'product_id' => $beer->id,
        'quantity' => 4, 'received_quantity' => 4, 'outcome' => 'received_full',
        'received_by' => $bartender->id, 'received_at' => $later,
    ]);

    expect($transfer->fresh()->load(['items', 'ingredientItems'])->received_at_label)
        ->toBe(VenueTime::convert($later)->format(StockTransfer::DISPLAY_DATE_FORMAT));
});

it('shows a dash rather than a blank where a transfer has not been received yet', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);

    $transfer = StockTransfer::create([
        'transfer_number' => 'ST-DATE-2', 'from_warehouse_id' => $main->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'sent',
    ]);

    expect($transfer->load(['items', 'ingredientItems'])->received_at_label)->toBeNull();
    expect($transfer->sent_at_label)->not->toBeNull();
});
