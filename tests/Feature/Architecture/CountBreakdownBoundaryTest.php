<?php

/**
 * The count breakdown only reads the stock ledger, debts, seals and
 * discrepancy rulings, and writes nothing but its own snapshot, open-order
 * and note tables. A breakdown that could move stock, book a debt, or
 * touch a seal/ruling would be a second, unreviewed path into the parts
 * of the system that hold staff accountable for money.
 */
it('never lets the breakdown code write stock, debts, seals or rulings', function () {
    $files = [
        'app/Services/CountBreakdownSnapshotService.php',
        'app/Services/CountBreakdownViewService.php',
        'app/Services/CountVarianceNoteService.php',
        'app/Services/CountBreakdownSettings.php',
        'app/Http/Controllers/CountBreakdownExportController.php',
        'app/Filament/Pages/CountItemTrace.php',
        'app/Filament/Ceo/Pages/CountBreakdowns.php',
    ];

    $forbidden = [
        // stock ledger / stock levels
        '/\b(InventoryTransaction|IngredientTransaction|InventoryItem|IngredientInventoryItem)::(create|insert|update|upsert|updateOrCreate|firstOrCreate|query\(\)\s*->\s*(update|delete|insert))/',
        '/->\s*(increment|decrement)\s*\(/',
        // debts
        '/\bStaffDebt(Repayment)?::(create|insert|update|upsert|updateOrCreate|firstOrCreate)/',
        // seal / review / discrepancy-resolution write methods
        '/->\s*(sealAgreement|declare|submitForReview|submitSoloCount|finalizeReview|reviewItem|bulkReviewItems|trueUpStock|chargeAccountability|debitDiscrepancy|writeOffDiscrepancy|acknowledgeOverage|pendDiscrepancyInvestigation|recordVerificationRecount|bulkDebitRemaining|bulkWriteOffRemaining|cancelSession)\s*\(/',
        '/\bHandoverDiscrepancy(Recount)?::(create|insert|update)/',
        // raw writes that would bypass all of the above
        '/DB::(table|statement|update|delete|insert)\s*\(/',
    ];

    $offences = [];

    foreach ($files as $file) {
        $source = file_get_contents(base_path($file));

        foreach ($forbidden as $pattern) {
            if (preg_match_all($pattern, $source, $matches)) {
                $offences[] = $file.': '.implode(', ', array_unique($matches[0]));
            }
        }
    }

    expect($offences)->toBe([]);
});

it('only creates rows in the breakdown tables', function () {
    $source = file_get_contents(base_path('app/Services/CountBreakdownSnapshotService.php'))
        .file_get_contents(base_path('app/Services/CountVarianceNoteService.php'));

    preg_match_all('/\b([A-Z]\w+)::create\(/', $source, $matches);

    expect(array_values(array_unique($matches[1])))->each->toBeIn([
        'CountBreakdown', 'CountBreakdownLine', 'CountBreakdownMovement', 'CountOpenOrder', 'CountVarianceNote',
    ]);
});
