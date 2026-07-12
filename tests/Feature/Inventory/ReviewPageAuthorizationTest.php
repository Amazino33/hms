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
 * Real production report: the outgoing custodian, viewing the count
 * session page on their own separate login (not the incoming's device),
 * could see and use the peer-review Accept/Dispute buttons meant only for
 * the incoming custodian. Root cause: iAmReviewer() only checked the
 * session-wide isIncomingBound() flag, never who is actually logged in —
 * so once ANYONE had bound as incoming, the review UI appeared for every
 * account that loaded the page, including the outgoing's own.
 */
function seedReviewAuthorizationScenario(): array
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

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, '5793', 'test-review-auth');
    $session = $service->bindIncomingCustodian($session, '2846', 'test-review-auth-bind');

    return compact('session', 'item', 'outgoing', 'incoming');
}

it('does not consider the outgoing custodian a reviewer, even after the incoming has bound', function () {
    ['session' => $session, 'outgoing' => $outgoing] = seedReviewAuthorizationScenario();

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);

    expect($component->instance()->iAmReviewer())->toBeFalse();
});

it('still considers the incoming custodian a reviewer on their own login', function () {
    ['session' => $session, 'incoming' => $incoming] = seedReviewAuthorizationScenario();

    $component = Livewire::actingAs($incoming)->test(CountSessionDetail::class, ['session_id' => $session->id]);

    expect($component->instance()->iAmReviewer())->toBeTrue();
});

it('refuses reviewAccept when called by the outgoing custodian instead of the incoming', function () {
    ['session' => $session, 'item' => $item, 'outgoing' => $outgoing] = seedReviewAuthorizationScenario();

    Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('reviewAccept', $item->id);

    expect($item->fresh()->review)->toBeNull();
});

it('refuses reviewDispute when called by the outgoing custodian instead of the incoming', function () {
    ['session' => $session, 'item' => $item, 'outgoing' => $outgoing] = seedReviewAuthorizationScenario();

    Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('reviewDispute', $item->id, ['Fridge' => 1]);

    expect($item->fresh()->review)->toBeNull();
});

it('refuses markItemUnresolved when called by the outgoing custodian instead of the incoming', function () {
    ['session' => $session, 'item' => $item, 'outgoing' => $outgoing] = seedReviewAuthorizationScenario();

    (new CountSessionService())->reviewProduct($item->fresh(), $session->incoming_user_id, 'disputed', ['Fridge' => 1]);

    Livewire::actingAs($outgoing)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('markItemUnresolved', $item->id);

    expect($item->fresh()->review->outcome)->toBe('disputed');
});

it('lets the incoming custodian accept a count through the page', function () {
    ['session' => $session, 'item' => $item, 'incoming' => $incoming] = seedReviewAuthorizationScenario();

    Livewire::actingAs($incoming)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->call('reviewAccept', $item->id);

    expect($item->fresh()->review->outcome)->toBe('accepted');
});
