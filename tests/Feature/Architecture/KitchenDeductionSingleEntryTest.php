<?php

/**
 * Phase 0D guard: kitchen food has one way off the shelf for anything that
 * reaches the kitchen screen — KitchenOrderService::markReady(). No
 * surface deducts on its own, and the creation path never deducts a
 * pending kitchen ticket (the behavioural half lives in
 * tests/Feature/Kitchen/FoodDeductsAtMarkReadyTest.php).
 *
 * OrderSplitter still legitimately calls the deduction for bar tickets
 * and for kitchen orders created already paid (takeaway / the full payment
 * screen), which never reach Mark Ready — so the rule pinned here is
 * "exactly these callers", not "only one caller".
 */
function sourceFilesCalling(string $needle): array
{
    $hits = [];

    foreach ([app_path(), resource_path('views')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), $needle)) {
                $hits[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
    }

    sort($hits);

    return $hits;
}

it('only lets the known entry points call the order stock deduction', function () {
    expect(sourceFilesCalling('deductInventoryForOrderItems('))->toBe([
        'app/Services/BarOrderService.php',        // bar Mark Ready (room drinks), extracted from BarDisplay in Phase 5
        'app/Services/InventoryService.php',       // the definition itself
        'app/Services/KitchenOrderService.php',    // THE kitchen Mark Ready deduction
        'app/Services/OrderSplitter.php',          // creation: bar + already-paid kitchen orders only
        // Phase 0F: processPayment() charging a SERVED original whose stock
        // never left the shelf (only possible from the old admin-form
        // bypass), before its re-creation — which itself never deducts.
        'resources/views/livewire/pos.blade.php',
    ]);
});

it('keeps both kitchen screens going through KitchenOrderService instead of deducting themselves', function () {
    foreach ([app_path('Filament/Pages/KitchenDisplay.php'), resource_path('views/livewire/kds-board.blade.php')] as $path) {
        $source = file_get_contents($path);

        expect($source)->toContain('KitchenOrderService');
        expect($source)->not->toContain('InventoryService::deduct');
    }
});

it('never lets the creation path deduct a pending kitchen ticket', function () {
    $source = file_get_contents(app_path('Services/OrderSplitter.php'));

    expect($source)->toContain("\$deferToMarkReady = \$destination === 'kitchen' && \$orderStatus === 'pending';");
    expect($source)->toContain("if (empty(\$options['defer_stock_deduction']) && ! \$deferToMarkReady) {");
});

it('only lets food that has already been made (Mark Ready, or a served order being paid) waive a stock shortfall', function () {
    expect(sourceFilesCalling('allowShortfall: true'))->toBe([
        'app/Services/KitchenOrderService.php',
        'resources/views/livewire/pos.blade.php', // Phase 0F catch-up for an already-served order
    ]);
});
