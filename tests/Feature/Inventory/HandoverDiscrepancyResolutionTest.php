<?php

use App\Filament\Pages\HandoverDiscrepancies;
use App\Models\HandoverDiscrepancy;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\CountSessionService;
use App\Services\PinAuthService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('debits the outgoing custodian at the frozen naira value and closes the line', function () {
    ['session' => $session, 'item' => $item, 'outgoing' => $outgoing] = sealedHandoverScenario(24, 20);
    $manager = User::factory()->create();

    $discrepancy = HandoverDiscrepancy::first();
    $service = new CountSessionService();
    $resolved = $service->debitDiscrepancy($discrepancy, $manager->id);

    expect($resolved->status)->toBe('debited');
    expect($resolved->resolved_by)->toBe($manager->id);

    $debt = StaffDebt::first();
    expect($debt)->not->toBeNull();
    expect($debt->user_id)->toBe($outgoing->id);
    expect((float) $debt->amount)->toBe(2000.0);
    expect($debt->reason)->toBe('count_session_shortfall');
    expect($resolved->staff_debt_id)->toBe($debt->id);
});

it('requires a note to pend a discrepancy for investigation', function () {
    sealedHandoverScenario(24, 20);
    $manager = User::factory()->create();
    $discrepancy = HandoverDiscrepancy::first();
    $service = new CountSessionService();

    expect(fn () => $service->pendDiscrepancyInvestigation($discrepancy, '', $manager->id))
        ->toThrow(Exception::class, 'An investigation note is required.');

    $pended = $service->pendDiscrepancyInvestigation($discrepancy, 'Checking CCTV footage.', $manager->id);
    expect($pended->status)->toBe('pending_investigation');
    expect($pended->investigation_note)->toBe('Checking CCTV footage.');
});

it('requires a written reason to resolve a discrepancy without a debit, and creates no StaffDebt', function () {
    sealedHandoverScenario(24, 20);
    $manager = User::factory()->create();
    $discrepancy = HandoverDiscrepancy::first();
    $service = new CountSessionService();

    expect(fn () => $service->writeOffDiscrepancy($discrepancy, '', $manager->id))
        ->toThrow(Exception::class, 'A written reason is required to resolve without a debit.');

    $resolved = $service->writeOffDiscrepancy($discrepancy, 'Unrecorded restock found, corrected.', $manager->id);
    expect($resolved->status)->toBe('written_off');
    expect(StaffDebt::count())->toBe(0);
});

it('refuses to resolve a discrepancy that has already been resolved', function () {
    sealedHandoverScenario(24, 20);
    $manager = User::factory()->create();
    $discrepancy = HandoverDiscrepancy::first();
    $service = new CountSessionService();
    $service->writeOffDiscrepancy($discrepancy, 'Reason', $manager->id);

    expect(fn () => $service->debitDiscrepancy($discrepancy->fresh(), $manager->id))
        ->toThrow(Exception::class, 'This discrepancy has already been resolved.');
});

it('recounts a discrepancy with counter+witness PIN, adjusts stock via InventoryTransaction, and returns it to pending_resolution', function () {
    ['session' => $session, 'item' => $item, 'bar' => $bar, 'product' => $product, 'outgoing' => $outgoing, 'outgoingPin' => $outgoingPin] = sealedHandoverScenario(24, 20);

    Role::firstOrCreate(['name' => 'storekeeper']);
    $witness = User::factory()->create();
    $witness->assignRole('storekeeper');
    (new PinAuthService())->setPin($witness, '4682');

    $discrepancy = HandoverDiscrepancy::first();
    $service = new CountSessionService();

    // Recount finds 22, not 20 — the shortfall was smaller than first thought.
    $recounted = $service->recordVerificationRecount(
        $discrepancy,
        22,
        $outgoingPin, // outgoing (bartender role) as counter
        '4682', // witness
        $outgoing->id,
        'recount-test',
    );

    expect($recounted->status)->toBe('pending_resolution');
    expect((float) $recounted->shortfall_quantity)->toBe(2.0); // 24 expected - 22 recounted
    expect((float) $recounted->naira_value)->toBe(1000.0); // 2 * frozen 500

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(22.0);
    expect(\App\Models\InventoryTransaction::where('type', 'adjustment')->where('reference', "handover_discrepancy:{$discrepancy->id}:recount")->exists())->toBeTrue();

    $recount = \App\Models\HandoverDiscrepancyRecount::first();
    expect($recount)->not->toBeNull();
    expect($recount->witnessed_by)->toBe($witness->id);

    // Original snapshot line is untouched by the recount.
    expect((float) $item->fresh()->variance_value)->toBe(-2000.0);
});

it('refuses a recount where the witness is the same person as the counter', function () {
    ['outgoing' => $outgoing] = sealedHandoverScenario(24, 20);
    $discrepancy = HandoverDiscrepancy::first();
    $service = new CountSessionService();

    expect(fn () => $service->recordVerificationRecount($discrepancy, 22, '5793', '5793', $outgoing->id, 'recount-same'))
        ->toThrow(Exception::class);
});

it('rejects non-managers from the HandoverDiscrepancies page', function () {
    sealedHandoverScenario(24, 20);

    Role::firstOrCreate(['name' => 'bartender']);
    $bartender = User::factory()->create();
    $bartender->assignRole('bartender');

    $this->actingAs($bartender);
    expect(HandoverDiscrepancies::canAccess())->toBeFalse();

    Role::firstOrCreate(['name' => 'manager']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    PagePermission::firstOrCreate(
        ['page_class' => HandoverDiscrepancies::class, 'role_name' => 'manager'],
        ['page_class' => HandoverDiscrepancies::class, 'page_name' => 'Handover Discrepancies', 'role_name' => 'manager']
    );
    $this->actingAs($manager);
    expect(HandoverDiscrepancies::canAccess())->toBeTrue();
});

it('bulk-resolves a mixed set of discrepancies, skipping already-resolved ones', function () {
    sealedHandoverScenario(24, 20);
    sealedHandoverScenario(24, 18);
    $manager = User::factory()->create();

    $discrepancies = HandoverDiscrepancy::all();
    expect($discrepancies)->toHaveCount(2);

    $service = new CountSessionService();
    $service->writeOffDiscrepancy($discrepancies->first(), 'already handled', $manager->id);

    $result = $service->bulkDebitRemaining($discrepancies, $manager->id);
    expect($result['debited'])->toBe(1);
    expect($result['failed'])->toBe(1);
});
