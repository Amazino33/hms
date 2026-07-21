<?php

use App\Filament\Pages\CountSessions;
use App\Filament\Pages\MyCount;
use App\Models\CountSession;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function grantMyCountPagePermissions(): void
{
    foreach (['bartender', 'chef'] as $role) {
        PagePermission::firstOrCreate(
            ['page_class' => MyCount::class, 'role_name' => $role],
            ['page_class' => MyCount::class, 'page_name' => 'My Handover Count', 'role_name' => $role]
        );
    }
}

it('tells a user with neither role there is nothing to count', function () {
    grantMyCountPagePermissions();
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));
    PagePermission::create(['page_class' => MyCount::class, 'page_name' => 'My Handover Count', 'role_name' => 'waiter']);

    Livewire::actingAs($waiter)
        ->test(MyCount::class)
        ->assertSee('set up as a bartender or chef');
});

it('offers a solo opening count to a bartender with no active shift, naming them as incoming', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->assertSee('Start Your Opening Count')
        ->assertDontSee('Handing over to')
        ->call('startCount')
        ->assertRedirect();

    $session = CountSession::where('type', 'bar_handover')->first();
    expect($session)->not->toBeNull();
    expect($session->outgoing_user_id)->toBeNull();
    expect($session->incoming_user_id)->toBe($bartender->id);
});

it('requires picking an incoming custodian before starting a handover count when already on shift', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->assertSee('Start Your Count')
        ->assertSee('Handing over to')
        ->call('startCount'); // no incomingUserId set

    expect(CountSession::count())->toBe(0);
});

it('starts a proper handover count naming the bartender as outgoing and the picked user as incoming', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $incoming = User::factory()->create();
    $incoming->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->set('incomingUserId', $incoming->id)
        ->call('startCount')
        ->assertRedirect();

    $session = CountSession::where('type', 'bar_handover')->first();
    expect($session->outgoing_user_id)->toBe($bartender->id);
    expect($session->incoming_user_id)->toBe($incoming->id);
});

it('starts a closing count when the bartender picks "Close for the day" instead of a handover', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $witness = User::factory()->create();
    $witness->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->set('isClosing', true)
        ->assertSee('Start Closing Count')
        ->set('incomingUserId', $witness->id)
        ->call('startCount')
        ->assertRedirect();

    $session = CountSession::where('type', 'bar_handover')->first();
    expect($session->outgoing_user_id)->toBe($bartender->id);
    expect($session->incoming_user_id)->toBe($witness->id);
    expect($session->isClosing())->toBeTrue();
});

it('offers to continue an already-open session instead of starting a second one', function () {
    grantMyCountPagePermissions();
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $session = (new \App\Services\CountSessionService())->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);

    Livewire::actingAs($bartender)
        ->test(MyCount::class)
        ->assertSee('You already have a count in progress')
        ->call('goToOpenSession')
        ->assertRedirect("/admin/count-session-detail?session_id={$session->id}");
});

it('resolves the kitchen warehouse and kitchen_handover type for a chef instead of bar', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::firstOrCreate(['id' => 5], ['name' => 'Kitchen', 'type' => 'consumer']);
    $chef = User::factory()->create();
    $chef->assignRole(Role::firstOrCreate(['name' => 'chef']));

    Livewire::actingAs($chef)
        ->test(MyCount::class)
        ->call('startCount');

    $session = CountSession::first();
    expect($session->type)->toBe('kitchen_handover');
    expect($session->warehouse_id)->toBe($kitchen->id);
});

it('no longer lets a bartender reach the generic admin Count Sessions list', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $this->actingAs($bartender);

    expect(CountSessions::canAccess())->toBeFalse();
});

it('still lets a bartender reach the count session detail page directly, without CountSessions access', function () {
    // Regression test: CountSessionDetail::canAccess() used to check
    // CountSessions' permission instead of its own, on the assumption it
    // was "always reached from" that list. MyCount's redirect broke that
    // assumption — a bartender with no CountSessions access still needs to
    // land on CountSessionDetail after MyCount opens a session for them.
    PagePermission::firstOrCreate(
        ['page_class' => \App\Filament\Pages\CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => \App\Filament\Pages\CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $this->actingAs($bartender);

    expect(CountSessions::canAccess())->toBeFalse();
    expect(\App\Filament\Pages\CountSessionDetail::canAccess())->toBeTrue();
});

it('alerts the incoming user to wait for the outgoing custodian, instead of offering an unwitnessed count by default', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);

    $absentOutgoing = User::factory()->create();
    $absentOutgoing->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create(['user_id' => $absentOutgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $incoming = User::factory()->create();
    $incoming->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($incoming)
        ->test(MyCount::class)
        ->assertSee("Waiting on {$absentOutgoing->name}'s handover", false)
        ->assertDontSee('Start an Unwitnessed Handover')
        ->assertDontSee('Start Your Opening Count');

    expect(CountSession::count())->toBe(0);
});

it('lets the incoming user fall through to an unwitnessed handover once they say the outgoing is not available', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);

    $absentOutgoing = User::factory()->create();
    $absentOutgoing->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create(['user_id' => $absentOutgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $incoming = User::factory()->create();
    $incoming->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $witness = User::factory()->create();
    $witness->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    (new \App\Services\PinAuthService())->setPin($witness, '9911');

    Livewire::actingAs($incoming)
        ->test(MyCount::class)
        ->set('showUnwitnessedOption', true)
        ->assertSee('Start an Unwitnessed Handover')
        ->assertDontSee('Start Your Opening Count')
        ->call('startCount') // no witnessUserId set yet
        ->assertSet('witnessUserId', null);

    expect(CountSession::count())->toBe(0);

    Livewire::actingAs($incoming)
        ->test(MyCount::class)
        ->set('showUnwitnessedOption', true)
        ->set('witnessUserId', $witness->id)
        ->call('startCount')
        ->assertRedirect();

    $session = CountSession::where('type', 'bar_handover')->first();
    expect($session)->not->toBeNull();
    expect($session->outgoing_user_id)->toBe($absentOutgoing->id);
    expect($session->incoming_user_id)->toBe($incoming->id);
    expect($session->witness_user_id)->toBe($witness->id);
    expect($session->isUnwitnessed())->toBeTrue();
});

it('checkAgain is a harmless no-op that keeps the alert state (nothing changed on the outgoing side)', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);

    $absentOutgoing = User::factory()->create();
    $absentOutgoing->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create(['user_id' => $absentOutgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    $incoming = User::factory()->create();
    $incoming->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($incoming)
        ->test(MyCount::class)
        ->call('checkAgain')
        ->assertSee("Waiting on {$absentOutgoing->name}'s handover", false);
});

/**
 * Regression for a real production incident: there is no fixed shift
 * schedule here (handovers happen whenever, not on a clock), so a
 * bartender's own overnight shift running ~22 hours is completely normal
 * — not abandoned. With the old 20-hour stale threshold, the outgoing
 * bartender's own shift silently stopped counting as "active" right as he
 * tried to hand over, which routed him into a solo opening count (framed
 * as if there were nobody to hand over to/from) instead of a real
 * handover, and separately blocked bar orders via the same staleness
 * check in OrderSplitter.
 */
it('still recognizes a bartender own overnight shift as active well past the old 20-hour threshold', function () {
    grantMyCountPagePermissions();
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);

    $outgoing = User::factory()->create();
    $outgoing->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(22), 'status' => 'active']);

    $incoming = User::factory()->create();
    $incoming->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Livewire::actingAs($outgoing)
        ->test(MyCount::class)
        ->assertSee('Start Your Count')
        ->assertDontSee('Start an Unwitnessed Handover')
        ->assertDontSee('Start Your Opening Count')
        ->set('incomingUserId', $incoming->id)
        ->call('startCount')
        ->assertRedirect();

    $session = CountSession::where('type', 'bar_handover')->first();
    expect($session->outgoing_user_id)->toBe($outgoing->id);
    expect($session->incoming_user_id)->toBe($incoming->id);
    expect($session->isUnwitnessed())->toBeFalse();
});
