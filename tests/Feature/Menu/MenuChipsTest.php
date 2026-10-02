<?php

use App\Filament\Pages\BarDisplay;
use App\Filament\Pages\KitchenDisplay;
use App\Filament\Resources\ChipGroups\Pages\ListChipGroups;
use App\Models\Category;
use App\Models\ChipGroup;
use App\Models\ChipOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Phase 1A — quick-choice chips. Order items keep a snapshot of the chosen
 * labels, and the bar display and KDS show them (and the note) under the
 * item. Nothing on a staff ordering screen can pick chips yet.
 */
function mcAdmin(): User
{
    // Same as every resource test: Shield permissions come from the seeder.
    test()->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

function mcOrderWithChips(string $destination, string $productName, array $chips, ?string $note): Order
{
    $table = TableModel::create(['name' => 'Table MC '.uniqid(), 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $category = Category::firstOrCreate(['name' => $destination === 'bar' ? 'Drinks' : 'Food'], ['type' => $destination === 'bar' ? 'drink' : 'food']);
    $product = Product::create(['name' => $productName, 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);

    $order = Order::create([
        'order_number' => 'ORD-MC-'.uniqid(), 'table_id' => $table->id, 'user_id' => User::factory()->create()->id,
        'status' => 'pending', 'destination' => $destination, 'total_amount' => 1000,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $productName,
        'item_type' => 'product', 'quantity' => 2, 'unit_price' => 1000, 'subtotal' => 2000,
        'chips' => $chips, 'note' => $note,
    ]);

    return $order;
}

it('seeds Temperature on drinks and Food extras on food when the tables are created', function () {
    // RefreshDatabase ran the migration before any category existed, so
    // re-run just its seeding step against categories that do exist now.
    DB::table('category_chip_group')->delete();
    DB::table('chip_options')->delete();
    DB::table('chip_groups')->delete();
    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $food = Category::create(['name' => 'Food', 'type' => 'food']);

    $migration = require database_path('migrations/2026_10_01_120100_create_chip_tables.php');
    \Illuminate\Support\Facades\Schema::dropIfExists('category_chip_group');
    \Illuminate\Support\Facades\Schema::dropIfExists('chip_options');
    \Illuminate\Support\Facades\Schema::dropIfExists('chip_groups');
    $migration->up();

    $temperature = ChipGroup::where('name', 'Temperature')->sole();
    expect($temperature->selection)->toBe('single');
    expect($temperature->options->pluck('label')->all())->toBe(['Cold', 'Not cold']);
    expect($temperature->categories->pluck('id')->all())->toBe([$drinks->id]);

    $extras = ChipGroup::where('name', 'Food extras')->sole();
    expect($extras->selection)->toBe('multiple');
    expect($extras->options->pluck('label')->all())->toBe(['Extra pepper', 'Less pepper', 'No onions']);
    expect($extras->categories->pluck('id')->all())->toBe([$food->id]);
    expect($food->fresh()->chipGroups->pluck('name')->all())->toBe(['Food extras']);
});

it('stores chips as label snapshots that a later rename never rewrites', function () {
    $cold = ChipOption::where('label', 'Cold')->sole();
    $order = mcOrderWithChips('bar', 'Star Beer', [$cold->label], 'No straw please');

    $cold->update(['label' => 'Chilled']);

    $item = $order->items()->sole();
    expect($item->chips)->toBe(['Cold']);
    expect($item->note)->toBe('No straw please');
    expect(DB::table('order_items')->where('id', $item->id)->value('chips'))->toBe('["Cold"]');
});

it('shows chips and the note on the bar display', function () {
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    mcOrderWithChips('bar', 'Star Beer', ['Cold'], 'No straw please');

    Livewire::actingAs(mcAdmin())
        ->test(BarDisplay::class)
        ->assertSee('Star Beer')
        ->assertSee('Cold')
        ->assertSee('No straw please');
});

it('shows chips and the note on the KDS ticket and the admin kitchen display', function () {
    mcOrderWithChips('kitchen', 'Pepper Soup', ['Extra pepper', 'No onions'], 'Allergic to crayfish');

    Livewire::test('kds-board')
        ->assertSee('Pepper Soup')
        ->assertSee('Extra pepper · No onions')
        ->assertSee('Allergic to crayfish');

    Livewire::actingAs(mcAdmin())
        ->test(KitchenDisplay::class)
        ->assertSee('Extra pepper · No onions')
        ->assertSee('Allergic to crayfish');
});

it('shows nothing extra when an item has no chips or note', function () {
    mcOrderWithChips('kitchen', 'Plain Rice', [], null);

    Livewire::test('kds-board')
        ->assertSee('Plain Rice')
        ->assertDontSee('“');
});

it('lists chip groups in the Menu chips admin screen', function () {
    Livewire::actingAs(mcAdmin())
        ->test(ListChipGroups::class)
        ->assertCanSeeTableRecords(ChipGroup::all());
});

it('keeps chip selection off every staff ordering screen', function () {
    // (pos.blade.php's x-mobile.chip-select is an unrelated button group.)
    // (OrderSplitter carries guest chips since Phase 3 — that's the guest
    // flow writing them, not a staff screen offering them.)
    foreach ([resource_path('views/livewire/pos.blade.php'), app_path('Filament/Pages/RoomOrder.php'), resource_path('views/filament/pages/room-order.blade.php')] as $path) {
        if (file_exists($path)) {
            expect(preg_match("/ChipGroup|ChipOption|chipGroups|'chips'/", file_get_contents($path)))->toBe(0, $path);
        }
    }
});
