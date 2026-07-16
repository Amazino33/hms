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
 * Part of the system-wide notification/silent-failure fix: pins that the
 * "every product must be reviewed" seal precondition (CountSessionService::
 * sealAgreement()) reaches the user as a persistent danger notification
 * through the real page, and that a blocked seal never advances the
 * session's status.
 */
function seedSealNotificationScenario(): array
{
    $bar = WareHouse::create(['name' => 'Bar ' . uniqid(), 'type' => 'consumer', 'is_active' => 1]);
    $category = Category::firstOrCreate(['name' => 'Drinks'], ['type' => 'drink']);
    $product = Product::create(['name' => 'Heineken ' . uniqid(), 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    Role::firstOrCreate(['name' => 'bartender']);
    $outgoing = User::factory()->create();
    $outgoing->assignRole('bartender');
    $incoming = User::factory()->create();
    $incoming->assignRole('bartender');

    $pinAuth = new PinAuthService();
    $outgoingPin = (string) random_int(1000, 9999);
    $incomingPin = (string) random_int(1000, 9999);
    $pinAuth->setPin($outgoing, $outgoingPin);
    $pinAuth->setPin($incoming, $incomingPin);

    Shift::create(['user_id' => $outgoing->id, 'type' => 'bartender', 'started_at' => now()->subHours(2), 'status' => 'active']);

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    return compact('bar', 'product', 'outgoing', 'incoming', 'outgoingPin', 'incomingPin');
}

it('blocks sealing while a product is still unreviewed, sending a persistent danger notification and leaving the session unsealed', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedSealNotificationScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, $outgoingPin, 'seal-notif-declare-' . uniqid());
    $service->bindIncomingCustodian($session, $incomingPin, 'seal-notif-bind-' . uniqid());
    // Deliberately skip reviewProduct() — the item stays unreviewed.

    session()->forget('filament.notifications');

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeFalse();

    $last = collect(session('filament.notifications', []))->last();
    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('danger');
    expect($last['duration'])->toBe('persistent');
    expect($last['title'])->toBe('Could not seal');
    expect($last['body'])->toContain('reviewed');

    expect($session->fresh()->status)->toBe('declared');
});

it('seals a fully-reviewed handover with a success notification', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming, 'outgoingPin' => $outgoingPin, 'incomingPin' => $incomingPin] = seedSealNotificationScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, $outgoingPin, 'seal-notif-declare-2-' . uniqid());
    $service->bindIncomingCustodian($session, $incomingPin, 'seal-notif-bind-2-' . uniqid());
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    session()->forget('filament.notifications');

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeTrue();

    $last = collect(session('filament.notifications', []))->last();
    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('success');
    expect($last['title'])->toBe('Handover sealed');

    expect($session->fresh()->status)->toBe('reviewed');
});
