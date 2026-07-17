<?php

use App\Filament\Pages\CountSessionDetail;
use App\Filament\Pages\HandoverDiscrepancies;
use App\Models\Category;
use App\Models\HandoverDiscrepancy;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\DamageReportService;
use App\Services\PinAuthService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Financial Foundations Part B, the two explicitly-decided handover
 * interactions: a pending damage never blocks the seal, and the manager's
 * discrepancy resolution screen surfaces it as decision-support context
 * without ever mutating the discrepancy's own frozen figures.
 */
it('lets a handover seal proceed normally even with a pending damage report open for the same product', function () {
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

    // A pending damage report sitting open against this exact product,
    // at this exact warehouse — must have zero bearing on whether the
    // seal itself succeeds.
    app(DamageReportService::class)->report(
        ['product_id' => $product->id, 'quantity' => 2, 'note' => 'Dropped bottle'],
        $bar->id,
        $outgoing->id,
    );

    $service = new CountSessionService();
    $session = $service->openSession('bar_handover', $bar->id, $outgoing->id, $outgoing->id, $incoming->id);
    $item = $session->items()->first();
    $service->recordCount($item, ['Fridge' => 24], $outgoing->id);
    $session = $service->declare($session, $outgoingPin, 'damage-seal-declare-' . uniqid());
    $service->bindIncomingCustodian($session, $incomingPin, 'damage-seal-bind-' . uniqid());
    $item->refresh();
    $service->reviewProduct($item, $incoming->id, 'accepted');

    PagePermission::firstOrCreate(
        ['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'],
        ['page_class' => CountSessionDetail::class, 'page_name' => 'Count Session Detail', 'role_name' => 'bartender']
    );

    $component = Livewire::actingAs($outgoing)->test(CountSessionDetail::class, ['session_id' => $session->id]);
    $ok = $component->instance()->sealAgreement($outgoingPin, $incomingPin);

    expect($ok)->toBeTrue();
    expect($session->fresh()->status)->toBe('reviewed');
});

it('surfaces pending damages as context on the discrepancy resolution screen without mutating the frozen discrepancy', function () {
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));
    PagePermission::firstOrCreate(
        ['page_class' => HandoverDiscrepancies::class, 'role_name' => 'manager'],
        ['page_class' => HandoverDiscrepancies::class, 'page_name' => 'Handover Discrepancies', 'role_name' => 'manager']
    );

    ['session' => $session, 'item' => $item, 'product' => $product, 'bar' => $bar, 'outgoing' => $outgoing] =
        sealedHandoverScenario(liveStockQuantity: 24, countedQuantity: 20);

    $discrepancy = HandoverDiscrepancy::where('count_session_item_id', $item->id)->firstOrFail();
    expect((float) $discrepancy->shortfall_quantity)->toBe(4.0);
    $originalShortfall = (float) $discrepancy->shortfall_quantity;
    $originalValue = (float) $discrepancy->naira_value;

    app(DamageReportService::class)->report(
        ['product_id' => $product->id, 'quantity' => 3, 'note' => 'Bottle slipped during handover'],
        $bar->id,
        $outgoing->id,
    );

    $page = Livewire::actingAs($manager)->test(HandoverDiscrepancies::class);
    $context = $page->instance()->pendingDamageContextFor($discrepancy);

    expect($context)->not->toBeNull();
    expect($context['damaged_qty'])->toBe(3.0);
    expect($context['remaining_qty'])->toBe(1.0); // 4 shortfall - 3 damaged, display-only

    // The frozen discrepancy record itself is completely untouched by
    // merely computing that context.
    expect((float) $discrepancy->fresh()->shortfall_quantity)->toBe($originalShortfall);
    expect((float) $discrepancy->fresh()->naira_value)->toBe($originalValue);
});

it('approving a pending damage from the discrepancy resolution screen writes off stock but still leaves the discrepancy open for the manager to explicitly rule', function () {
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));
    PagePermission::firstOrCreate(
        ['page_class' => HandoverDiscrepancies::class, 'role_name' => 'manager'],
        ['page_class' => HandoverDiscrepancies::class, 'page_name' => 'Handover Discrepancies', 'role_name' => 'manager']
    );

    ['item' => $item, 'product' => $product, 'bar' => $bar, 'outgoing' => $outgoing] =
        sealedHandoverScenario(liveStockQuantity: 24, countedQuantity: 20);

    $discrepancy = HandoverDiscrepancy::where('count_session_item_id', $item->id)->firstOrFail();
    $report = app(DamageReportService::class)->report(
        ['product_id' => $product->id, 'quantity' => 3, 'note' => 'Bottle slipped'],
        $bar->id,
        $outgoing->id,
    );

    $stockBefore = \App\Models\InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity');

    $page = Livewire::actingAs($manager)->test(HandoverDiscrepancies::class);
    $page->call('approvePendingDamage', $report->id);

    expect($report->fresh()->status)->toBe('approved');
    $stockAfter = \App\Models\InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity');
    expect((float) $stockAfter)->toBe((float) $stockBefore - 3);

    // The manager still has to explicitly rule on the discrepancy itself —
    // approving the damage is not itself a ruling.
    expect($discrepancy->fresh()->isOpen())->toBeTrue();
});
