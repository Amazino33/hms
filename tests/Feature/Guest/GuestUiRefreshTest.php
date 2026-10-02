<?php

use App\Models\Company;
use App\Models\Room;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Services\BookingService;
use App\Services\BrandingLogo;
use App\Services\ReservationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/**
 * Phase 7A — guest UI refresh: brand theme (D30), thumb-first layout (D31),
 * splash (D32). UI only: endpoints are pinned by GuestJsonShapesTest.
 */
function uiPlaces(): array
{
    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $room = Room::create(['number' => '7', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);

    $desk = User::factory()->create();
    $desk->assignRole(Role::firstOrCreate(['name' => 'receptionist']));
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Ada Eze', 'guest_phone' => '08061234567',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $desk->id);
    (new BookingService)->checkIn($booking, $desk->id);

    return compact('table', 'room');
}

function uiPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 166, 25, 46));
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

function uiSplash(string $html): string
{
    return str($html)->between('<div id="splash"', '<script type="application/json" id="guest-boot">')->toString();
}

afterEach(function () {
    File::delete([BrandingLogo::publicPath(BrandingLogo::LOGO), BrandingLogo::publicPath(BrandingLogo::SPLASH), BrandingLogo::publicPath(BrandingLogo::MARK)]);
});

it('puts the splash inline with the company name and the place; the menu-only link has no place pill', function () {
    Company::updateOrCreate(['id' => 1], ['name' => 'Selum Lounge & Suites']);
    ['table' => $table, 'room' => $room] = uiPlaces();

    $tablePage = $this->get('/m/'.$table->qr_token)->assertOk()->getContent();
    $splash = uiSplash($tablePage);
    expect($splash)->toContain('WELCOME TO')
        ->toContain('Selum Lounge &amp; Suites')
        ->toContain('<span class="pill">Table 5</span>');

    expect(uiSplash($this->get('/m/'.$room->qr_token)->getContent()))->toContain('<span class="pill">Room 7</span>');

    $menuSplash = uiSplash($this->get('/menu')->assertOk()->getContent());
    expect($menuSplash)->toContain('Selum Lounge &amp; Suites');
    expect(str_contains($menuSplash, 'class="pill"'))->toBeFalse();

    // Shown once per code per 4 hours, skippable, capped at 2 s.
    expect($tablePage)->toContain("'selum_splash_'")->toContain('4 * 3600 * 1000')->toContain('setTimeout(leave, 2000)')->toContain("addEventListener('click', leave)");
});

it('preloads the splash crest and never asks Google for fonts', function () {
    Storage::fake('local');
    Storage::disk('local')->put('company-logos/crest.png', uiPng(900, 900));
    Company::updateOrCreate(['id' => 1], ['name' => 'Selum', 'logo_path' => 'company-logos/crest.png']);
    ['table' => $table] = uiPlaces();

    expect(is_file(BrandingLogo::publicPath(BrandingLogo::SPLASH)))->toBeTrue();
    expect(getimagesize(BrandingLogo::publicPath(BrandingLogo::SPLASH))[0])->toBe(480);

    $html = $this->get('/m/'.$table->qr_token)->assertOk()->getContent();
    expect($html)->toMatch('#<link rel="preload" as="image" href="/media/branding/logo-splash\.webp\?v=\d+">#');

    $everything = $html.File::get(resource_path('css/guest.css')).File::get(resource_path('js/guest.js'));
    foreach (glob(public_path('build/assets/guest-*')) as $built) {
        $everything .= File::get($built);
    }
    expect(str_contains($everything, 'fonts.googleapis.com'))->toBeFalse();
    expect(str_contains($everything, 'fonts.gstatic.com'))->toBeFalse();
});

it('takes the venue name from Company Settings everywhere on the guest page', function () {
    ['table' => $table] = uiPlaces();

    Company::updateOrCreate(['id' => 1], ['name' => 'First Name Bar']);
    $first = $this->get('/m/'.$table->qr_token)->getContent();
    expect(uiSplash($first))->toContain('First Name Bar');
    expect($first)->toContain('<span class="venue">First Name Bar</span>');

    Company::whereKey(1)->update(['name' => 'Renamed Lounge']);
    $second = $this->get('/m/'.$table->qr_token)->getContent();
    expect(uiSplash($second))->toContain('Renamed Lounge');
    expect($second)->toContain('<span class="venue">Renamed Lounge</span>');
    expect(str_contains($second, 'First Name Bar'))->toBeFalse();
});

it('turns a small logo mark upload into a ≤ 96 px header logo, and falls back to the full logo without one', function () {
    Storage::fake('local');
    Storage::disk('local')->put('company-logos/crest.png', uiPng(900, 900));
    Company::updateOrCreate(['id' => 1], ['name' => 'Selum', 'logo_path' => 'company-logos/crest.png']);
    ['table' => $table] = uiPlaces();

    // No mark: the header uses the full logo.
    expect($this->get('/m/'.$table->qr_token)->getContent())->toMatch('#<span class="medallion">\s*<img src="/media/branding/logo\.webp\?v=\d+"#');

    $file = UploadedFile::fake()->createWithContent('shield.png', uiPng(600, 700));
    expect(BrandingLogo::publishMark($file))->toBe('logo-mark.webp');
    [$width] = getimagesize(BrandingLogo::publicPath(BrandingLogo::MARK));
    expect($width)->toBeLessThanOrEqual(96);
    expect($this->get('/m/'.$table->qr_token)->getContent())->toMatch('#<span class="medallion">\s*<img src="/media/branding/logo-mark\.webp\?v=\d+"#');

    // Removed on the settings page: back to the full logo.
    BrandingLogo::removeMark();
    expect(BrandingLogo::headerUrl())->toStartWith('/media/branding/logo.webp');

    // HEIC is refused like any menu photo.
    expect(fn () => BrandingLogo::publishMark(UploadedFile::fake()->create('shield.heic', 10, 'image/heic')))->toThrow(Exception::class, 'HEIC');
});

it('saves and clears the logo mark from Guest Ordering Settings', function () {
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    \Livewire\Livewire::actingAs($manager)->test(\App\Filament\Pages\GuestOrdering::class)
        ->set('data.logo_mark', [UploadedFile::fake()->createWithContent('shield.png', uiPng(300, 300))])
        ->call('save')
        ->assertNotified('Guest ordering settings saved');
    expect(BrandingLogo::hasMark())->toBeTrue();

    \Livewire\Livewire::actingAs($manager)->test(\App\Filament\Pages\GuestOrdering::class)
        ->set('data.logo_mark', null)
        ->call('save');
    expect(BrandingLogo::hasMark())->toBeFalse();
});

it('has four nav items, the last Waiter at a table and Reception in a room (D34)', function () {
    ['table' => $table, 'room' => $room] = uiPlaces();

    $nav = fn (string $html) => str($html)->between('<nav class="nav" aria-label="Main">', '</nav>')->toString();

    $tableNav = $nav($this->get('/m/'.$table->qr_token)->getContent());
    expect($tableNav)->toContain('Drinks')->toContain('Food')->toContain('Bill')->toContain('Waiter');
    expect(str_contains($tableNav, 'Reception'))->toBeFalse();

    $roomNav = $nav($this->get('/m/'.$room->qr_token)->getContent());
    expect($roomNav)->toContain('Reception');
    expect(str_contains($roomNav, 'Waiter'))->toBeFalse();

    // Browse-only (menu link): no Bill, no Waiter.
    $menuNav = $nav($this->get('/menu')->getContent());
    expect($menuNav)->toContain('Drinks')->toContain('Food');
    expect(str_contains($tableNav, 'Search'))->toBeFalse(); // D34: search lives in the top bar
    expect(str_contains($menuNav, 'Bill'))->toBeFalse();
});

it('creates zero session rows for the refreshed guest pages (D9)', function () {
    ['table' => $table, 'room' => $room] = uiPlaces();
    DB::table('sessions')->delete();

    $this->get('/m/'.$table->qr_token)->assertOk();
    $this->get('/m/'.$room->qr_token)->assertOk();
    $this->get('/menu')->assertOk();
    $this->get('/m/NOTAREALCODE')->assertNotFound();

    expect(DB::table('sessions')->count())->toBe(0);
});

it('keeps gold out of the guest theme and never uses red for errors (architecture)', function () {
    $css = File::get(resource_path('css/guest.css'));

    expect(preg_match('/#E0B35A/i', $css))->toBe(0);
    expect(preg_match('/#f59e0b/i', File::get(resource_path('views/guest/layout.blade.php'))))->toBe(0);

    // Every rule for an error/warning class may only use the amber token.
    preg_match_all('/([^{}]*\b(?:error|warn)[^{}]*)\{([^}]*)\}/i', $css, $rules, PREG_SET_ORDER);
    expect($rules)->not->toBeEmpty();
    foreach ($rules as [, $selector, $body]) {
        expect(preg_match('/--red|#A6192E|#E04355|--danger/i', $body))->toBe(0, "Error style uses red: {$selector}");
    }
    expect($css)->toContain('.toast.error { border-color: var(--warn)');
    expect(preg_match('/--danger/', $css))->toBe(0);
});

it('never types the venue name into a guest view (architecture)', function () {
    foreach (glob(resource_path('views/guest/*.blade.php')) as $view) {
        $source = File::get($view);
        // The name as a word (the lowercase 'selum_splash_' storage key is not the name).
        expect(preg_match('/\bSelum\b/', $source))->toBe(0, basename($view).' hard-codes the venue name');
    }

    // The name arrives only through the accessor.
    expect(File::get(app_path('Http/Controllers/GuestMenuController.php')))->toContain("'venue' => Company::displayName()");
    expect(File::get(resource_path('views/guest/menu.blade.php')))->toContain('{{ $venue }}');
});
