<?php

use App\Filament\Pages\GuestOrdering;
use App\Filament\Pages\QrCodes;
use App\Models\QrTokenRegeneration;
use App\Models\Room;
use App\Models\Table as TableModel;
use App\Models\TransferAccount;
use App\Models\User;
use App\Services\Guest\GuestOrderingSettings;
use App\Services\Guest\QrPrintSheets;
use App\Services\Guest\QrTokens;
use App\Services\SettingsService;
use App\Support\NigerianPhone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 1B — guest ordering settings, QR tokens, QR admin page and the
 * placeholder guest routes.
 */
function gqUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => $role]));

    return $user;
}

function gqTable(string $name = 'Table 5'): TableModel
{
    return TableModel::create(['name' => $name, 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
}

function gqRoom(string $number = '7'): Room
{
    return Room::create(['number' => $number, 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
}

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
});

it('gives every existing table and room a unique 10-character token in the migration backfill', function () {
    // Rows that predate the column: written without the model, then the
    // migration's backfill step re-run against them.
    DB::table('tables')->insert([['name' => 'T1', 'capacity' => 2, 'status' => 'available', 'location' => 'Main'], ['name' => 'T2', 'capacity' => 2, 'status' => 'available', 'location' => 'Main']]);
    DB::table('rooms')->insert([['number' => '101', 'price_per_night' => 1, 'status' => 'available', 'housekeeping' => 'clean']]);
    DB::table('tables')->update(['qr_token' => null]);
    DB::table('rooms')->update(['qr_token' => null]);

    $migration = require database_path('migrations/2026_10_01_130100_add_qr_token_to_tables_and_rooms.php');
    $migration->down();
    $migration->up();

    $tokens = DB::table('tables')->pluck('qr_token')->merge(DB::table('rooms')->pluck('qr_token'));

    expect($tokens)->toHaveCount(3);
    expect($tokens->unique())->toHaveCount(3);
    $tokens->each(fn ($t) => expect($t)->toMatch('/^[0-9A-Za-z]{10}$/'));
});

it('gives a newly created table or room a token automatically, never derived from its id or name', function () {
    $table = gqTable('Table 9');
    $room = gqRoom('9');

    expect($table->qr_token)->toMatch('/^[0-9A-Za-z]{10}$/');
    expect($room->qr_token)->toMatch('/^[0-9A-Za-z]{10}$/');
    expect($table->qr_token)->not->toBe($room->qr_token);

    // Two tables with the same name never share a token.
    expect(gqTable('Table 9b')->qr_token)->not->toBe($table->qr_token);
});

it('regenerates a token: old link dies with the same 404 page, reason logged, old token stored nowhere', function () {
    $table = gqTable('Table 5');
    $old = $table->qr_token;
    $manager = gqUser('manager');

    $new = QrTokens::regenerate($table, $manager, 'Card photographed and posted online');

    expect($new)->not->toBe($old)->toMatch('/^[0-9A-Za-z]{10}$/');
    expect($table->fresh()->qr_token)->toBe($new);

    $this->get("/m/{$old}")->assertNotFound()->assertSee('This code is no longer valid')->assertDontSee('Table 5');
    $this->get("/m/{$new}")->assertOk()->assertSee('Table 5');

    $log = QrTokenRegeneration::sole();
    expect($log->subject_type)->toBe(TableModel::class);
    expect($log->subject_id)->toBe($table->id);
    expect($log->regenerated_by)->toBe($manager->id);
    expect($log->reason)->toBe('Card photographed and posted online');

    // The old token survives in no table at all.
    foreach (['tables', 'rooms', 'qr_token_regenerations', 'activity_log'] as $tableName) {
        $rows = json_encode(DB::table($tableName)->get());
        expect($rows)->not->toContain($old);
    }
});

it('refuses to regenerate without a reason', function () {
    $table = gqTable();
    $old = $table->qr_token;

    expect(fn () => QrTokens::regenerate($table, gqUser('manager'), '  '))->toThrow(Exception::class, 'Say why');
    expect($table->fresh()->qr_token)->toBe($old);
    expect(QrTokenRegeneration::count())->toBe(0);
});

it('shows the placeholder with the right table or room name for a valid token', function () {
    $table = gqTable('Table 5');
    $room = gqRoom('7');

    // Phase 2 replaced the placeholders with the real menu page.
    $this->get("/m/{$table->qr_token}")->assertOk()->assertSee('Table 5');
    $this->get("/m/{$room->qr_token}")->assertOk()->assertSee('Room 7')->assertSee('Ordering is available during your stay.'); // Phase 5: no stay, no ordering
    $this->get('/menu')->assertOk()->assertSee('Scan the QR code on your table to order');
});

it('gives an unknown token exactly the same 404 page as a regenerated one', function () {
    $table = gqTable('Table 5');
    $old = $table->qr_token;
    QrTokens::regenerate($table, gqUser('manager'), 'Lost');

    $regenerated = $this->get("/m/{$old}");
    $unknown = $this->get('/m/ZZZZZZZZZZ');
    $malformed = $this->get('/m/'.urlencode('../../etc'));

    foreach ([$regenerated, $unknown, $malformed] as $response) {
        $response->assertNotFound()->assertSee('This code is no longer valid');
    }

    expect($unknown->getContent())->toBe($regenerated->getContent());
});

it('rate-limits the guest menu page to 60 a minute per phone (D10)', function () {
    $device = str_repeat('a', 32);

    foreach (range(1, 60) as $i) {
        $this->withUnencryptedCookie('selum_gd', $device)->get('/menu')->assertOk();
    }

    $this->withUnencryptedCookie('selum_gd', $device)->get('/menu')->assertStatus(429);
});

it('saves a WhatsApp number typed as 0801… in international form, and rejects a bad one', function () {
    Livewire::actingAs(gqUser('manager'))
        ->test(GuestOrdering::class)
        ->set('data.reception_whatsapp', '0801 234 5678')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SettingsService::get(GuestOrderingSettings::RECEPTION_WHATSAPP))->toBe('2348012345678');

    Livewire::actingAs(gqUser('manager'))
        ->test(GuestOrdering::class)
        ->set('data.reception_whatsapp', '0612345678')
        ->call('save')
        ->assertHasFormErrors(['reception_whatsapp']);

    expect(SettingsService::get(GuestOrderingSettings::RECEPTION_WHATSAPP))->toBe('2348012345678');

    expect(NigerianPhone::toInternational('+234 801 234 5678'))->toBe('2348012345678');
    expect(NigerianPhone::toInternational('07031234567'))->toBe('2347031234567');
    expect(NigerianPhone::toInternational('0123'))->toBeNull();
});

it('rejects an account number that is not exactly 10 digits, and saves a good one in order', function () {
    Livewire::actingAs(gqUser('manager'))
        ->test(GuestOrdering::class)
        ->set('data.accounts', [['id' => null, 'bank_name' => 'GTBank', 'account_name' => 'Selum Ltd', 'account_number' => '01234567', 'active' => true]])
        ->call('save')
        ->assertHasFormErrors(['accounts.0.account_number']);

    expect(TransferAccount::count())->toBe(0);

    Livewire::actingAs(gqUser('manager'))
        ->test(GuestOrdering::class)
        ->set('data.accounts', [
            ['id' => null, 'bank_name' => 'GTBank', 'account_name' => 'Selum Ltd', 'account_number' => '0123456789', 'active' => true],
            ['id' => null, 'bank_name' => 'Moniepoint', 'account_name' => 'Selum Bar', 'account_number' => '9876543210', 'active' => true],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TransferAccount::active()->pluck('bank_name')->all())->toBe(['GTBank', 'Moniepoint']);
});

it('keeps the settings and QR pages away from non-manager roles, server-side', function () {
    foreach (['waiter', 'cashier', 'receptionist', 'bartender', 'chef', 'porter', 'ceo'] as $role) {
        $user = gqUser($role);
        $this->actingAs($user);

        expect(GuestOrdering::canAccess())->toBeFalse("{$role} can open settings");
        expect(QrCodes::canAccess())->toBeFalse("{$role} can open QR codes");
    }

    // And an action request can't slip past the page gate.
    $table = gqTable();
    $old = $table->qr_token;
    $waiter = gqUser('waiter');
    $this->actingAs($waiter);
    $page = new QrCodes;
    $page->openRegenerate('table', $table->id);
    $page->regenerateReason = 'Trying it';
    $page->regenerate();
    expect($table->fresh()->qr_token)->toBe($old);

    foreach (['manager', 'admin', 'super_admin'] as $role) {
        $this->actingAs(gqUser($role));
        expect(GuestOrdering::canAccess())->toBeTrue();
        expect(QrCodes::canAccess())->toBeTrue();
    }
});

it('regenerates from the QR page with a reason', function () {
    $room = gqRoom('7');
    $old = $room->qr_token;

    Livewire::actingAs(gqUser('manager'))
        ->test(QrCodes::class)
        ->set('tab', 'rooms')
        ->assertSee('Room 7')
        ->call('openRegenerate', 'room', $room->id)
        ->set('regenerateReason', 'Sticker damaged')
        ->call('regenerate')
        ->assertNotified('New QR code for Room 7');

    expect($room->fresh()->qr_token)->not->toBe($old);
});

it('generates the print-all PDFs for tables, rooms and the menu poster', function () {
    gqTable('Table 1');
    gqTable('Table 2');
    gqRoom('101');

    $sheets = new QrPrintSheets;

    foreach ([$sheets->tables(), $sheets->rooms(), $sheets->menu()] as $pdf) {
        expect(substr($pdf, 0, 5))->toBe('%PDF-');
        expect(strlen($pdf))->toBeGreaterThan(1000);
    }

    Livewire::actingAs(gqUser('manager'))
        ->test(QrCodes::class)
        ->call('printTables')
        ->assertFileDownloaded('qr-table-cards.pdf');
});

it('downloads a table QR as a PNG', function () {
    $table = gqTable('Table 5');

    Livewire::actingAs(gqUser('manager'))
        ->test(QrCodes::class)
        ->call('downloadPng', 'table', $table->id)
        ->assertFileDownloaded('qr-table-5.png');
});
