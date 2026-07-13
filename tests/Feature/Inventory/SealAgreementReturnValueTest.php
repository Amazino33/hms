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

function seedSealReturnValueScenario(): array
{
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

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

/**
 * Real production report: the dual-PIN seal screen only surfaces a wrong
 * first (outgoing/witness) PIN's error after the second PIN is typed too —
 * by which point the bad first PIN is already held in Alpine state with no
 * way back except a full page reload. Someone hit this live: a wrong first
 * entry meant every retry kept re-submitting the same bad PIN and failing
 * on "Outgoing signature", no matter how many times they re-typed the
 * second one. Root cause: sealAgreement() returned void, so the JS had no
 * way to know the attempt failed and reset itself back to a clean first
 * entry — the same silent-success class of bug recordCount() had before.
 */
it('returns false from the page-level sealAgreement when the first (outgoing) PIN is wrong', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedSealReturnValueScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-seal-return-declare');
    $service->bindIncomingCustodian($session, '2846', 'test-seal-return-bind');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $component = Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id]);

    $ok = $component->instance()->sealAgreement('0000', '2846');

    expect($ok)->toBeFalse();
    expect($session->fresh()->status)->toBe('declared');
});

it('returns true from the page-level sealAgreement on a genuine successful seal', function () {
    ['bar' => $bar, 'outgoing' => $outgoing, 'incoming' => $incoming] = seedSealReturnValueScenario();

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-seal-return-declare-2');
    $service->bindIncomingCustodian($session, '2846', 'test-seal-return-bind-2');
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $component = Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id]);

    $ok = $component->instance()->sealAgreement('5793', '2846');

    expect($ok)->toBeTrue();
    expect($session->fresh()->status)->toBe('reviewed');
});

/**
 * True Alpine/DOM behavior (auto-reset to step 1 on failure, the manual
 * Back button) isn't reachable from Pest — this pins the markup wiring it
 * depends on instead, the same way the count-flush gate was pinned earlier.
 */
it('wires the seal screen to reset back to the first PIN entry on a failed seal', function () {
    $view = file_get_contents(resource_path('views/filament/pages/partials/count-session-dual-seal.blade.php'));

    expect($view)->toContain("if (!ok) { step = 'first'; firstPin = null }");
    expect($view)->toContain("step = 'first'; firstPin = null\"");
});
