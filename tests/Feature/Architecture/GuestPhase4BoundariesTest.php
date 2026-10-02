<?php

/**
 * Phase 4 guards for the guest flow: who may write claims, move orders
 * between tables, and close a sitting — and that claims and table moves
 * are never deleted.
 */
function g4Sources(): array
{
    $files = [];

    foreach ([app_path(), resource_path('views')] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = file_get_contents($file->getPathname());
            }
        }
    }

    ksort($files);

    return $files;
}

function g4FilesMatching(string $pattern, array $except = []): array
{
    return collect(g4Sources())
        ->filter(fn ($source, $path) => ! in_array($path, $except, true) && preg_match($pattern, $source))
        ->keys()
        ->values()
        ->all();
}

it('only lets GuestClaimService and GuestTablePaymentService write payment claims (D23)', function () {
    $writes = '/GuestPaymentClaim::[^;]*\b(create|forceCreate|insert|update|upsert|updateOrCreate|firstOrCreate)\s*\(|\$claim\s*->\s*(update|save|fill|forceFill)\s*\(|[\'"]guest_payment_claims[\'"]\)[^;]*->\s*(insert|update|upsert)\s*\(/';

    expect(g4FilesMatching($writes, ['app/Models/GuestPaymentClaim.php']))->toBe([
        'app/Services/Guest/GuestClaimService.php',
        'app/Services/Guest/GuestTablePaymentService.php',
    ]);
});

it('only lets TableMoveService change the table an order belongs to', function () {
    // Any update of orders that writes table_id, anywhere in the app.
    $moves = '/Order::[^;]*->\s*update\s*\(\s*\[[^\]]*[\'"]table_id[\'"]\s*=>|\$order\s*->\s*update\s*\(\s*\[[^\]]*[\'"]table_id[\'"]|[\'"]orders[\'"]\)[^;]*->\s*update\s*\(\s*\[[^\]]*[\'"]table_id[\'"]/s';

    expect(g4FilesMatching($moves))->toBe(['app/Services/Guest/TableMoveService.php']);

    // And no other guest code assigns table_id on an order model.
    $guestCode = collect(g4Sources())->filter(fn ($s, $path) => str_starts_with($path, 'app/Services/Guest/')
        || str_contains($path, 'guest') || str_contains($path, 'Guest'));

    foreach ($guestCode as $path => $source) {
        if ($path === 'app/Services/Guest/TableMoveService.php') {
            continue;
        }

        expect(preg_match('/\$order\s*->\s*table_id\s*=/', $source))->toBe(0, "{$path} sets an order's table_id");
    }
});

it('only lets TableCloseService (and guest:expire-stale through it) close a sitting', function () {
    $closes = '/[\'"]close_reason[\'"]\s*=>/';

    expect(g4FilesMatching($closes))->toBe(['app/Services/Guest/TableCloseService.php']);

    // guest:expire-stale reaches it through GuestRequestService::expireStale().
    expect(file_get_contents(app_path('Services/Guest/GuestRequestService.php')))->toContain('(new TableCloseService)->closeStale()');
    expect(file_get_contents(app_path('Console/Commands/ExpireStaleGuestRequests.php')))->toContain('->expireStale()');

    // Nothing else writes a sitting's closed_at.
    $sittingCloses = collect(g4Sources())
        ->filter(fn ($s) => str_contains($s, 'GuestTableSession'))
        ->filter(fn ($s) => preg_match('/[\'"]closed_at[\'"]\s*=>\s*now\(\)/', $s))
        ->keys()->values()->all();
    expect($sittingCloses)->toBe(['app/Services/Guest/TableCloseService.php']);
});

it('never deletes a table move or a payment claim', function () {
    $deletes = '/(TableMove|GuestPaymentClaim)::[^;]*->\s*(delete|forceDelete|truncate)\s*\(|(TableMove|GuestPaymentClaim)::(destroy|truncate)\s*\(|[\'"](table_moves|guest_payment_claims)[\'"]\)[^;]*->\s*(delete|truncate)\s*\(|\$(move|claim)\s*->\s*(delete|forceDelete)\s*\(/';

    expect(g4FilesMatching($deletes))->toBe([]);

    foreach (['TableMove', 'GuestPaymentClaim'] as $model) {
        expect(file_get_contents(app_path("Models/{$model}.php")))->toContain('static::deleting(');
    }
});
