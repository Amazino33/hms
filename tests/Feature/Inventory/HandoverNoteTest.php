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
 * The handover note on the dual-PIN peer-sealed handover screen (git
 * 3c2518d) is optional, not required — sealing must succeed whether or not
 * one is written, and a note that is provided gets trimmed and saved onto
 * the session for the record.
 */
function seedHandoverNoteScenario(): array
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
    $session = $service->declare($session, $outgoingPin, 'handover-note-declare-'.uniqid());
    $service->bindIncomingCustodian($session, $incomingPin, 'handover-note-bind-'.uniqid());
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    return compact('bar', 'product', 'outgoing', 'incoming', 'outgoingPin', 'incomingPin', 'session');
}

it('seals successfully with no note at all', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedHandoverNoteScenario();

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeTrue();
    expect($session->fresh()->status)->toBe('reviewed');
    expect($session->fresh()->notes)->toBeNull();
});

it('seals successfully with a whitespace-only note, saving nothing', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedHandoverNoteScenario();

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $component->set('handoverNote', "   \n  ");
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeTrue();
    expect($session->fresh()->status)->toBe('reviewed');
    expect($session->fresh()->notes)->toBeNull();
});

it('seals and saves the trimmed note onto the session once provided', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedHandoverNoteScenario();

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $component->set('handoverNote', '  Two crates arrived damaged.  ');
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeTrue();
    expect($session->fresh()->status)->toBe('reviewed');
    expect($session->fresh()->notes)->toBe('Two crates arrived damaged.');
});

it('shows the saved handover note on the count session detail page', function () {
    ['outgoing' => $outgoing, 'session' => $session, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedHandoverNoteScenario();

    Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->set('handoverNote', 'Fridge was warm this morning.')
        ->call('sealAgreement', $outgoingPin, $incomingPin);

    Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->assertSee('Fridge was warm this morning.');
});

it('labels the note field as optional on the seal screen', function () {
    $view = file_get_contents(resource_path('views/filament/pages/partials/count-session-dual-seal.blade.php'));

    expect($view)->toContain('Handover Note (Optional)');
    expect($view)->not->toContain('Handover Note (Required)');
});
