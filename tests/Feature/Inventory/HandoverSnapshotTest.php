<?php

use App\Models\HandoverDiscrepancy;
use App\Models\StaffDebt;

it('freezes unit selling price and variance value on the item at seal time', function () {
    ['session' => $session, 'item' => $item] = sealedHandoverScenario(24, 20);

    $item = $item->fresh();
    expect((float) $item->unit_selling_price)->toBe(500.0);
    expect((float) $item->variance)->toBe(-4.0);
    expect((float) $item->variance_value)->toBe(-2000.0);
    expect((float) $session->fresh()->total_shortage_value)->toBe(2000.0);
});

it('does not create a StaffDebt at seal — only a pending_resolution discrepancy', function () {
    sealedHandoverScenario(24, 20);

    expect(StaffDebt::count())->toBe(0);
    $discrepancy = HandoverDiscrepancy::first();
    expect($discrepancy)->not->toBeNull();
    expect($discrepancy->status)->toBe('pending_resolution');
    expect((float) $discrepancy->naira_value)->toBe(2000.0);
});

it('never changes a sealed sessions historic naira values when the products price changes afterward', function () {
    ['item' => $item, 'product' => $product, 'session' => $session] = sealedHandoverScenario(24, 20);

    $product->update(['price' => 5000]); // price hike after the fact

    $item = $item->fresh();
    expect((float) $item->unit_selling_price)->toBe(500.0); // unchanged
    expect((float) $item->variance_value)->toBe(-2000.0); // unchanged
    expect((float) $session->fresh()->total_shortage_value)->toBe(2000.0); // unchanged

    $discrepancy = HandoverDiscrepancy::first();
    expect((float) $discrepancy->unit_price)->toBe(500.0);
    expect((float) $discrepancy->naira_value)->toBe(2000.0);
});

it('does not create a discrepancy for an overage — only tracks it as a session-level quantity total', function () {
    ['session' => $session] = sealedHandoverScenario(20, 24); // counted more than live stock

    expect(HandoverDiscrepancy::count())->toBe(0);
    expect((float) $session->fresh()->total_shortage_value)->toBe(0.0);
    expect((float) $session->fresh()->total_overage_quantity)->toBe(4.0);
});
