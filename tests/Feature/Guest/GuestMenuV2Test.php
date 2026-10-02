<?php

use App\Filament\Pages\GuestOrdering;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\GuestItemPairing;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestOrderingSettings;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\RoomRequestApprovalService;
use App\Services\PinAuthService;
use App\Services\ReservationService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 7C — Guest Menu v2 (D34–D38): owner-set selling fields, the
 * added_via label, and the additive payload keys behind the new UI.
 */
function gm7Fixture(): array
{
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $drinks = Category::create(['name' => 'Beers', 'type' => 'drink']);
    $food = Category::create(['name' => 'Rice', 'type' => 'food']);

    $stock = function (string $name, int $price, bool $active = true) use ($bar, $drinks) {
        $product = Product::create(['name' => $name, 'price' => $price, 'category_id' => $drinks->id, 'is_active' => $active]);
        InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 40]);

        return $product;
    };
    $beer = $stock('Star Beer', 1000);
    $malt = $stock('Maltina', 800);
    $coke = $stock('Coke', 500);
    $retired = $stock('Old Stout', 1200, false);
    $jollof = MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-GM7-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]);

    $waiter = User::factory()->create(['name' => 'Emeka Obi']);
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));
    (new PinAuthService)->setPin($waiter, '4826');
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $bartender = User::factory()->create(['name' => 'Bisi Bar']);
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    $chef = User::factory()->create(['name' => 'Tola Chef']);
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);
    $desk = User::factory()->create(['name' => 'Kemi Desk']);
    $desk->assignRole(Role::firstOrCreate(['name' => 'receptionist']));

    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $room = Room::create(['number' => '7', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Ada Eze', 'guest_phone' => '08061234567',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $desk->id);
    (new BookingService)->checkIn($booking, $desk->id);
    Cache::flush();

    return compact('beer', 'malt', 'coke', 'retired', 'jollof', 'waiter', 'desk', 'table', 'room');
}

function gm7Admin(): User
{
    test()->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

function gm7As(string $device)
{
    return test()->withCredentials()->withUnencryptedCookie('selum_gd', str_repeat($device, 32));
}

function gm7Boot(string $html): array
{
    return json_decode(str($html)->between('<script type="application/json" id="guest-boot">', '</script>')->toString(), true);
}

function gm7Submit(TableModel|Room $place, string $device, array $lines, ?string $channel = null): GuestRequest
{
    return (new GuestRequestService)->submit($place->qr_token, str_repeat($device, 32), $lines, $channel);
}

/** Every item in the boot menu, keyed — in menu order. */
function gm7Items(array $boot): array
{
    $items = [];
    foreach ($boot['menu']['tabs'] as $sections) {
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $items[$item['key']] = $item;
            }
        }
    }

    return $items;
}

// ---- 1. Admin fields --------------------------------------------------------

it('saves badge, recommended, sort order and pairings from the admin forms', function () {
    $f = gm7Fixture();
    $admin = gm7Admin();

    Livewire::actingAs($admin)
        ->test(EditMenuItem::class, ['record' => $f['jollof']->getRouteKey()])
        ->fillForm([
            'guest_badge' => 'chefs_special',
            'guest_recommended' => true,
            'guest_sort' => 3,
            'guest_pairs' => ['p'.$f['malt']->id, 'p'.$f['beer']->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $jollof = $f['jollof']->fresh();
    expect($jollof->guest_badge)->toBe('chefs_special')
        ->and($jollof->guest_recommended)->toBeTrue()
        ->and($jollof->guest_sort)->toBe(3)
        ->and(GuestItemPairing::keysFor($jollof))->toBe(['p'.$f['malt']->id, 'p'.$f['beer']->id]);

    \App\Models\Unit::firstOrCreate(['name' => 'bottle']);
    $f['beer']->update(['base_unit' => 'bottle']);
    Livewire::actingAs($admin)
        ->test(EditProduct::class, ['record' => $f['beer']->getRouteKey()])
        ->fillForm(['guest_badge' => 'bestseller', 'guest_pairs' => ['m'.$f['jollof']->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($f['beer']->fresh()->guest_badge)->toBe('bestseller')
        ->and(GuestItemPairing::keysFor($f['beer']))->toBe(['m'.$f['jollof']->id]);
});

it('rejects a 4th pairing and an item paired with itself', function () {
    $f = gm7Fixture();
    $admin = gm7Admin();
    $four = ['p'.$f['beer']->id, 'p'.$f['malt']->id, 'p'.$f['coke']->id, 'p'.$f['retired']->id];

    Livewire::actingAs($admin)
        ->test(EditMenuItem::class, ['record' => $f['jollof']->getRouteKey()])
        ->fillForm(['guest_pairs' => $four])
        ->call('save')
        ->assertHasFormErrors(['guest_pairs']);

    Livewire::actingAs($admin)
        ->test(EditMenuItem::class, ['record' => $f['jollof']->getRouteKey()])
        ->fillForm(['guest_pairs' => ['m'.$f['jollof']->id]])
        ->call('save')
        ->assertHasFormErrors(['guest_pairs']);

    expect(GuestItemPairing::count())->toBe(0);

    // The model refuses too, whatever calls it.
    expect(fn () => GuestItemPairing::syncFor($f['jollof'], $four))->toThrow(InvalidArgumentException::class);
    expect(fn () => GuestItemPairing::syncFor($f['beer'], ['p'.$f['beer']->id]))->toThrow(InvalidArgumentException::class);
    expect(fn () => GuestItemPairing::syncFor($f['beer'], ['p999999']))->toThrow(InvalidArgumentException::class);
});

// ---- 2. ALL CAPS filter -----------------------------------------------------

it('filters products down to names typed in ALL CAPS', function () {
    $f = gm7Fixture();
    $shouty = Product::create(['name' => 'GULDER LAGER', 'price' => 900, 'category_id' => $f['beer']->category_id, 'is_active' => true]);
    $digits = Product::create(['name' => '33', 'price' => 900, 'category_id' => $f['beer']->category_id, 'is_active' => true]);

    Livewire::actingAs(gm7Admin())
        ->test(ListProducts::class)
        ->filterTable('all_caps_name')
        ->assertCanSeeTableRecords([$shouty])
        ->assertCanNotSeeTableRecords([$f['beer'], $f['malt'], $digits]);
});

// ---- 3. Settings ------------------------------------------------------------

it('validates and saves the selling settings', function () {
    $f = gm7Fixture();
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    Livewire::actingAs($manager)->test(GuestOrdering::class)
        ->assertSet('data.round_delay_min', GuestOrderingSettings::ROUND_DELAY_DEFAULT)
        ->set('data.round_delay_min', 121)
        ->set('data.review_url', 'http://g.page/selum')
        ->call('save')
        ->assertHasErrors(['data.round_delay_min', 'data.review_url']);

    Livewire::actingAs($manager)->test(GuestOrdering::class)
        ->set('data.quick_addons', ['p'.$f['coke']->id, 'p'.$f['malt']->id])
        ->set('data.round_delay_min', 0)
        ->set('data.review_url', 'https://g.page/r/selum/review')
        ->set('data.specials_whatsapp_message', 'Send me tonight\'s specials')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified('Guest ordering settings saved');

    expect(GuestOrderingSettings::quickAddons())->toBe(['p'.$f['coke']->id, 'p'.$f['malt']->id])
        ->and(GuestOrderingSettings::roundDelayMinutes())->toBe(0)
        ->and(GuestOrderingSettings::reviewUrl())->toBe('https://g.page/r/selum/review')
        ->and(GuestOrderingSettings::specialsWhatsappMessage())->toBe('Send me tonight\'s specials');

    // The setters hold the same lines on their own.
    $seven = collect(range(1, 7))->map(fn ($n) => 'p'.Product::create(['name' => "Mixer {$n}", 'price' => 300, 'category_id' => $f['coke']->category_id, 'is_active' => true])->id)->all();
    expect(fn () => GuestOrderingSettings::setQuickAddons($seven, $manager))->toThrow(Exception::class, 'at most 6');
    expect(fn () => GuestOrderingSettings::setQuickAddons(['p999999'], $manager))->toThrow(Exception::class);
    expect(fn () => GuestOrderingSettings::setRoundDelayMinutes(-1, $manager))->toThrow(Exception::class);
    expect(fn () => GuestOrderingSettings::setRoundDelayMinutes(121, $manager))->toThrow(Exception::class);
    expect(fn () => GuestOrderingSettings::setReviewUrl('javascript:alert(1)', $manager))->toThrow(Exception::class);
    GuestOrderingSettings::setReviewUrl(null, $manager);
    expect(GuestOrderingSettings::reviewUrl())->toBeNull();
});

// ---- 4. Menu payload --------------------------------------------------------

it('adds badge, recommended, sort and pairs to the menu, suggesting only what can be ordered', function () {
    $f = gm7Fixture();
    $f['coke']->update(['guest_badge' => 'new', 'guest_sort' => 1]);
    $f['malt']->update(['guest_badge' => 'not-a-badge']);
    GuestItemPairing::syncFor($f['jollof'], ['p'.$f['beer']->id, 'p'.$f['retired']->id]);
    $manager = User::factory()->create();
    GuestOrderingSettings::setQuickAddons(['p'.$f['retired']->id, 'p'.$f['coke']->id], $manager);
    Cache::flush();

    $boot = gm7Boot(gm7As('a')->get('/m/'.$f['table']->qr_token)->assertOk()->getContent());
    $items = gm7Items($boot);

    expect($items['p'.$f['coke']->id])->toMatchArray(['badge' => 'new', 'recommended' => false, 'sort' => 1, 'pairs' => []])
        ->and($items['p'.$f['malt']->id]['badge'])->toBeNull()
        // The retired stout is off the menu, so it is never suggested.
        ->and($items['m'.$f['jollof']->id]['pairs'])->toBe(['p'.$f['beer']->id])
        ->and($boot['quick_addons'])->toBe(['p'.$f['coke']->id])
        ->and($boot['round_delay_min'])->toBe(20)
        ->and($boot['review_url'])->toBeNull()
        ->and($boot['last_visit'])->toBeNull()
        ->and($boot['hint_items'])->not->toBeEmpty();

    // Sort order first, then name: Coke (1), then Maltina, Star Beer.
    $drinks = collect($boot['menu']['tabs']['drinks'])->flatMap(fn ($s) => $s['items'])->pluck('name')->all();
    expect($drinks)->toBe(['Coke', 'Maltina', 'Star Beer']);

    // No owner picks: "We recommend" falls back to tonight's real sellers.
    expect($boot['recommended'])->toBe($boot['popular']);

    $f['malt']->update(['guest_recommended' => true]);
    Cache::flush();
    $boot = gm7Boot($this->get('/menu')->assertOk()->getContent());
    expect($boot['recommended'])->toBe(['p'.$f['malt']->id])
        ->and($boot['last_visit'])->toBeNull();
});

// ---- 5. Order again ---------------------------------------------------------

it('offers "Order again" only to the same phone, after the visit closed, at today\'s prices', function () {
    $f = gm7Fixture();
    $request = gm7Submit($f['table'], 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2]]);
    (new GuestRequestService)->confirm($request, $f['waiter']);
    (new GuestBarReleaseService)->markReady($request);

    // Still the same sitting: nothing to "order again" yet.
    expect(gm7Boot(gm7As('a')->get('/m/'.$f['table']->qr_token)->getContent())['last_visit'])->toBeNull();

    $request->fresh()->session->update(['closed_at' => now()]);
    $f['beer']->update(['price' => 1200]);
    Cache::flush();

    $mine = gm7Boot(gm7As('a')->get('/m/'.$f['table']->qr_token)->getContent())['last_visit'];
    expect($mine['summary'])->toContain('Star Beer')
        ->and($mine['lines'])->toHaveCount(1)
        ->and($mine['lines'][0])->toMatchArray(['key' => 'p'.$f['beer']->id, 'qty' => 2, 'price' => 1200]);

    // Another phone, even at the same table, sees nothing.
    expect(gm7Boot(gm7As('b')->get('/m/'.$f['table']->qr_token)->getContent())['last_visit'])->toBeNull();
});

// ---- 6 + 9. Bill response: status strip and last round ------------------------

it('reports the latest order\'s status, the accepting waiter\'s first name and the last round', function () {
    $f = gm7Fixture();
    $bill = fn (string $device = 'a') => gm7As($device)->getJson('/m/'.$f['table']->qr_token.'/bill')->assertOk();

    expect($bill()->json())->toMatchArray(['latest_status' => null, 'accepted_by_first_name' => null, 'last_round' => null]);

    $drinks = gm7Submit($f['table'], 'a', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 2]]);
    expect($bill()->json('latest_status'))->toBe('waiting');

    (new GuestRequestService)->confirm($drinks, $f['waiter']);
    expect($bill()->json())->toMatchArray(['latest_status' => 'accepted', 'accepted_by_first_name' => 'Emeka'])
        ->and($bill()->json('last_round'))->toBeNull();

    (new GuestBarReleaseService)->markReady($drinks);
    $round = $bill()->json('last_round');
    expect($bill()->json('latest_status'))->toBe('ready')
        ->and($round['ref'])->toBe($drinks->ref)
        ->and($round['ready_at'])->not->toBeNull()
        ->and($round['superseded'])->toBeFalse()
        ->and($round['total'])->toBe(2000)
        ->and($round['lines'][0])->toMatchArray(['name' => 'Star Beer', 'qty' => 2, 'price' => 1000]);

    // Food in the kitchen: "preparing".
    $food = gm7Submit($f['table'], 'a', [['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1]]);
    (new GuestRequestService)->confirm($food, $f['waiter']);
    expect($bill()->json('latest_status'))->toBe('preparing')
        ->and($bill()->json('last_round.superseded'))->toBeFalse();

    // Newer drinks ordered: that round has been had again.
    gm7Submit($f['table'], 'a', [['type' => 'product', 'id' => $f['malt']->id, 'qty' => 1]]);
    expect($bill()->json('last_round.superseded'))->toBeTrue();

    // Never another phone's orders.
    expect($bill('b')->json())->toMatchArray(['latest_status' => null, 'last_round' => null]);
});

it('never names a receptionist on a room order', function () {
    $f = gm7Fixture();
    $request = gm7Submit($f['room'], 'r', [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1]], 'whatsapp');
    $bill = fn () => gm7As('r')->getJson('/m/'.$f['room']->qr_token.'/bill')->assertOk();

    expect($bill()->json('latest_status'))->toBe('waiting');
    (new RoomRequestApprovalService)->approve($request, $f['desk']);
    expect($bill()->json('latest_status'))->not->toBe('waiting')
        ->and($bill()->json('accepted_by_first_name'))->toBeNull();
});

// ---- 7. added_via -------------------------------------------------------------

it('stores added_via as a label only — same price, station and status — and refuses an unknown one', function () {
    $f = gm7Fixture();
    $t = '/m/'.$f['table']->qr_token;

    gm7As('a')->postJson("$t/requests", ['lines' => [
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1],
        ['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1, 'added_via' => 'round'],
        ['type' => 'menu_item', 'id' => $f['jollof']->id, 'qty' => 1, 'added_via' => 'pairing'],
    ]])->assertCreated();

    $lines = GuestRequestItem::orderBy('id')->get();
    expect($lines->pluck('added_via')->all())->toBe(['menu', 'round', 'pairing']);
    [$plain, $round] = [$lines[0], $lines[1]];
    expect((string) $round->unit_price_snapshot)->toBe((string) $plain->unit_price_snapshot)
        ->and($round->station)->toBe($plain->station)
        ->and($round->status)->toBe($plain->status);

    // Every label the page sends is accepted (each confirmed, so the
    // pending-orders cap never gets in the way).
    foreach (\App\Support\GuestMenuOptions::ADDED_VIA as $via) {
        $request = gm7Submit($f['table'], 'v', [['type' => 'product', 'id' => $f['coke']->id, 'qty' => 1, 'added_via' => $via]]);
        expect($request->items()->value('added_via'))->toBe($via);
        (new GuestRequestService)->confirm($request, $f['waiter']);
    }

    $before = GuestRequestItem::count();
    gm7As('c')->postJson("$t/requests", ['lines' => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1, 'added_via' => 'free_beer']]])
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'code' => 'bad_added_via']);
    gm7As('c')->postJson("$t/requests", ['lines' => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1, 'added_via' => ['menu']]]])
        ->assertStatus(422);
    expect(GuestRequestItem::count())->toBe($before);
});

// ---- 8. Specials -------------------------------------------------------------

it('builds the WhatsApp specials link from the reception number, and gives specials their end time', function () {
    $f = gm7Fixture();
    $manager = User::factory()->create();

    // No reception number, no link.
    GuestOrderingSettings::setReceptionWhatsapp(null, $manager);
    expect(GuestOrderingSettings::specialsWhatsappUrl())->toBeNull();

    GuestOrderingSettings::setReceptionWhatsapp('08012345678', $manager);
    expect(GuestOrderingSettings::specialsWhatsappUrl())->toBe('https://wa.me/2348012345678?text='.rawurlencode('Hi! Please send me your specials 🙂'));

    GuestOrderingSettings::setSpecialsWhatsappMessage('Specials please', $manager);
    expect(GuestOrderingSettings::specialsWhatsappUrl())->toBe('https://wa.me/2348012345678?text=Specials%20please');

    $ends = now()->addMinutes(40)->startOfMinute();
    GuestOrderingSettings::saveSpecials(['text' => 'Happy hour', 'image_path' => null, 'active' => true, 'starts_at' => null, 'ends_at' => $ends], $manager);
    Cache::flush();

    $boot = gm7Boot(gm7As('a')->get('/m/'.$f['table']->qr_token)->getContent());
    expect($boot['specials_whatsapp_url'])->toBe('https://wa.me/2348012345678?text=Specials%20please')
        ->and(\Carbon\CarbonImmutable::parse($boot['specials']['ends_at'])->equalTo($ends))->toBeTrue();
});

// ---- 10. The page itself -----------------------------------------------------

it('draws the top bar, the closed-table page and the selling blocks on the guest page', function () {
    $f = gm7Fixture();
    $page = gm7As('a')->get('/m/'.$f['table']->qr_token)->assertOk()->getContent();

    expect($page)->toContain('<header class="topbar">')
        ->toContain('@click="openSearch()"')
        ->toContain('You\'re ordering for Table 5')
        ->toContain('class="status-strip"')
        ->toContain('We recommend')
        ->toContain('Goes well with')
        ->toContain('Anything else?')
        ->toContain('Ready for another round?')
        ->toContain('Thanks for visiting')
        ->toContain('Table 5 is closed. We hope to see you again soon.')
        ->toContain('Rate us on Google')
        ->toContain('Only if you want. You can stop anytime.')
        ->toContain('A waiter will confirm it shortly');
    expect(str_contains($page, 'Good evening') || str_contains($page, 'Good morning') || str_contains($page, 'Good afternoon'))->toBeFalse();

    $room = gm7As('r')->get('/m/'.$f['room']->qr_token)->assertOk()->getContent();
    expect($room)->toContain('Reception will confirm it shortly');
    expect(str_contains($room, 'Thanks for visiting'))->toBeFalse();
});

it('keeps guest motion to transform and opacity, each with a reduced-motion form', function () {
    $css = File::get(resource_path('css/guest.css'));

    preg_match_all('/@keyframes\s+([\w-]+)\s*\{((?:[^{}]*\{[^}]*\})*)\s*\}/', $css, $frames, PREG_SET_ORDER);
    expect($frames)->not->toBeEmpty();
    foreach ($frames as [, $name, $body]) {
        preg_match_all('/([\w-]+)\s*:/', preg_replace('/^[^{]*\{|\}[^{]*\{/', ';', $body), $props);
        expect(array_diff(array_unique($props[1]), ['transform', 'opacity', 'background-position']))->toBe([], "@keyframes {$name} animates more than transform/opacity");
    }

    $reduced = str($css)->after('@media (prefers-reduced-motion: reduce)')->before("\n}\n")->toString();
    foreach (['.place-pill.glow::after', '.sdot.wait', '.sent-ring', '.fly-dot.go', '.nav button.bounce', '.nudge'] as $selector) {
        expect($reduced)->toContain($selector);
    }
});

// ---- 11. Stateless -------------------------------------------------------------

it('creates zero session rows across the v2 page, bill and submit', function () {
    $f = gm7Fixture();
    DB::table('sessions')->delete();
    $t = '/m/'.$f['table']->qr_token;

    gm7As('a')->get($t)->assertOk();
    gm7As('a')->postJson("$t/requests", ['lines' => [['type' => 'product', 'id' => $f['beer']->id, 'qty' => 1, 'added_via' => 'recommended']]])->assertCreated();
    gm7As('a')->getJson("$t/bill")->assertOk();
    gm7As('r')->getJson('/m/'.$f['room']->qr_token.'/bill')->assertOk();
    $this->get('/menu')->assertOk();

    expect(DB::table('sessions')->count())->toBe(0);
});

// ---- 12. Architecture ------------------------------------------------------------

it('reads added_via nowhere in the app except where GuestRequestService validates and stores it', function () {
    $readers = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (PhpToken::tokenize(File::get($file->getPathname())) as $token) {
            if (in_array($token->id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_STRING], true) && str_contains($token->text, 'added_via')) {
                $readers[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
    }

    expect(array_values(array_unique($readers)))->toBe(['Services/Guest/GuestRequestService.php']);
});
