<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\Category;
use App\Models\CountSession;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\CountSessionService;
use App\Services\PinAuthService;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function seedIdentityBindingScenario(int $quantity = 24): array
{
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => $quantity]);

    Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($outgoing, '5793');
    $pinAuth->setPin($incoming, '2846');

    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    return compact('bar', 'product', 'outgoing', 'incoming');
}

it('binds the incoming custodian by PIN lookup at review start, overwriting the outgoing custodian\'s guess', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedIdentityBindingScenario();

    $service = new CountSessionService();
    // openSession's incoming_user_id is only ever a guess — simulate the
    // outgoing custodian guessing WRONG by naming a different bartender.
    $wronglyGuessedIncoming = User::factory()->create();
    $wronglyGuessedIncoming->assignRole('bartender');

    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $wronglyGuessedIncoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-bind-1');

    expect($session->isIncomingBound())->toBeFalse();

    // The real incoming custodian shows up and confirms with their own PIN —
    // this must succeed and rebind incoming_user_id to them, even though the
    // guess named someone else entirely.
    $session = $service->bindIncomingCustodian($session, '2846', 'test-bind-1-confirm');

    expect($session->incoming_user_id)->toBe($incoming->id);
    expect($session->isIncomingBound())->toBeTrue();

    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');
    $session = $service->sealAgreement($session, '5793', '2846', 'test-bind-1-seal');

    expect($session->status)->toBe('reviewed');
});

it('refuses to bind the incoming custodian to the same person as the outgoing', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedIdentityBindingScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-bind-2');

    expect(fn () => $service->bindIncomingCustodian($session, '5793', 'test-bind-2-self'))
        ->toThrow(Exception::class, 'The incoming custodian cannot be the same person as the outgoing custodian.');
});

it('refuses to bind a PIN that does not belong to a bartender', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedIdentityBindingScenario();
    Role::firstOrCreate(['name' => 'storekeeper']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole('storekeeper');
    (new PinAuthService())->setPin($storekeeper, '9012');

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-bind-3');

    expect(fn () => $service->bindIncomingCustodian($session, '9012', 'test-bind-3-role'))
        ->toThrow(Exception::class, 'Only a bartender can review this count.');
});

it('leaves the seal unreachable until the incoming custodian has bound their identity', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedIdentityBindingScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-bind-4');
    $item->refresh();

    // reviewProduct() can still be called directly (it's a lower-level API,
    // and incoming_user_id already happens to match in this test) — but the
    // real bug is sealing without ever having bound via PIN.
    $service->reviewProduct($item, $incoming->id, 'accepted');

    expect(fn () => $service->sealAgreement($session, '5793', '2846', 'test-bind-4-seal'))
        ->toThrow(Exception::class, 'The incoming custodian must confirm their identity via PIN (at review start) before this can be sealed.');
});

it('seals successfully with correct PINs no matter which account is logged into the kiosk', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedIdentityBindingScenario();

    Role::firstOrCreate(['name' => 'bartender']);
    $unrelatedBartender = User::factory()->create();
    $unrelatedBartender->assignRole('bartender');
    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-anyacct-declare');
    $service->bindIncomingCustodian($session, '2846', 'test-anyacct-bind');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    // The device is logged in as a THIRD bartender, unrelated to this
    // session entirely — sealAgreement() must not care.
    $component = Livewire::actingAs($unrelatedBartender)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('sealAgreement', '5793', '2846');

    expect($session->fresh()->status)->toBe('reviewed');
});

it('fails the seal with a named error when the incoming PIN belongs to someone else, regardless of the guessed incoming_user_id', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedIdentityBindingScenario();

    $someoneElse = User::factory()->create();
    $someoneElse->assignRole('bartender');
    (new PinAuthService())->setPin($someoneElse, '4455');

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-wrongpin-declare');
    $service->bindIncomingCustodian($session, '2846', 'test-wrongpin-bind');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    expect(fn () => $service->sealAgreement($session, '5793', '4455', 'test-wrongpin-seal'))
        ->toThrow(Exception::class, "Incoming signature: PIN does not match {$incoming->name}'s PIN.");
});

it('auto-binds the witness by PIN lookup at co-sign time on the unwitnessed path', function () {
    ['bar' => $bar, 'outgoing' => $absentOutgoing, 'incoming' => $incoming] = seedIdentityBindingScenario();
    Role::firstOrCreate(['name' => 'storekeeper']);
    $guessedWitness = User::factory()->create();
    $guessedWitness->assignRole('storekeeper');
    $actualWitness = User::factory()->create();
    $actualWitness->assignRole('storekeeper');

    $pinAuth = new PinAuthService();
    $pinAuth->setPin($actualWitness, '1357'); // guessedWitness's PIN is never entered

    $service = new CountSessionService();
    $session = $service->openSession(
        'bar_handover',
        $bar->id,
        $incoming->id,
        outgoingUserId: $absentOutgoing->id,
        incomingUserId: $incoming->id,
        witnessUserId: $guessedWitness->id, // the guess — different from who actually shows up
    );

    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 20], $incoming->id);

    // The real witness (not the guessed one) co-signs with their own PIN.
    $session = $service->sealAgreement($session, '1357', '2846', 'test-witness-autobind');

    expect($session->status)->toBe('reviewed');
    expect($session->fresh()->witness_user_id)->toBe($actualWitness->id);
});
