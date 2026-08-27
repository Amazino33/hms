<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\PinAuthService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The compulsory handover note (git 3c2518d): the dual-PIN peer-sealed
 * handover screen requires a non-blank note before it will seal at all —
 * intentionally scoped to that one flow only (isHandoverWithSuccessor()),
 * never main_store_stocktake or a manager-reviewed session.
 */
function seedCompulsoryNoteScenario(): array
{
    $bar = WareHouse::create(['name' => 'Bar '.uniqid(), 'type' => 'consumer', 'is_active' => 1]);
    $category = Category::firstOrCreate(['name' => 'Drinks'], ['type' => 'drink']);
    $product = Product::create(['name' => 'Heineken '.uniqid(), 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new PinAuthService;
    $outgoingPin = (string) random_int(1000, 9999);
    $incomingPin = (string) random_int(1000, 9999);
    $pinAuth->setPin($outgoing, $outgoingPin);
    $pinAuth->setPin($incoming, $incomingPin);

    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $service = new CountSessionService;
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, $outgoingPin, 'compulsory-note-declare-'.uniqid());
    $service->bindIncomingCustodian($session, $incomingPin, 'compulsory-note-bind-'.uniqid());
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    return compact('bar', 'product', 'outgoing', 'incoming', 'outgoingPin', 'incomingPin', 'session');
}

it('refuses to seal with a blank handover note, leaving the session unsealed', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedCompulsoryNoteScenario();

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeFalse();
    expect($session->fresh()->status)->not->toBe('reviewed');

    $last = collect(session('filament.notifications', []))->last();
    expect($last['title'])->toBe('A handover note is required.');
});

it('refuses to seal with a whitespace-only handover note', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedCompulsoryNoteScenario();

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $component->set('handoverNote', "   \n  ");
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeFalse();
    expect($session->fresh()->status)->not->toBe('reviewed');
});

it('seals and saves the trimmed note onto the session once provided', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedCompulsoryNoteScenario();

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $component->set('handoverNote', '  Two crates arrived damaged.  ');
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeTrue();
    expect($session->fresh()->status)->toBe('reviewed');
    expect($session->fresh()->notes)->toBe('Two crates arrived damaged.');
});

it('shows the saved handover note on the count session detail page', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedCompulsoryNoteScenario();

    Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->set('handoverNote', 'Fridge was warm this morning.')
        ->call('sealAgreement', $outgoingPin, $incomingPin);

    Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->assertSee('Fridge was warm this morning.');
});

/**
 * The compulsory note is deliberately scoped to the dual-PIN peer-sealed
 * handover only (sealAgreement() itself throws for any session that isn't
 * isHandoverWithSuccessor()) — a manager-reviewed session (finalizeReview(),
 * used by main_store_stocktake and by manager review generally) has no such
 * requirement and must be unaffected.
 */
it('does not require a note on a manager-reviewed session, which never goes through sealAgreement at all', function () {
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::firstOrCreate(['name' => 'Drinks'], ['type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $mainStore->id, 'quantity' => 20]);

    $storekeeper = User::factory()->create();
    $manager = User::factory()->create();

    $service = new CountSessionService;
    $session = $service->openSession('main_store_stocktake', $mainStore->id, $storekeeper->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Shelf A' => 20], $storekeeper->id);
    $session = $service->submitForReview($session->fresh());

    $reviewed = $service->finalizeReview($session, $manager->id);

    expect($reviewed->status)->toBe('reviewed');
});
