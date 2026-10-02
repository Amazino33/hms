<?php

/**
 * Phase 5 guards for guest room ordering: who approves room requests, who
 * moves a delivery along, who cancels a room order from guest code, and
 * that the new append-only tables are never deleted from.
 */
function g5Sources(): array
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

function g5FilesMatching(string $pattern, array $except = []): array
{
    return collect(g5Sources())
        ->filter(fn ($source, $path) => ! in_array($path, $except, true) && preg_match($pattern, $source))
        ->keys()
        ->values()
        ->all();
}

/** The body of one method, from its signature to the next method. */
function g5Method(string $source, string $name): string
{
    preg_match('/function '.$name.'\(.*?(?=\n    (?:public|private|protected) (?:static )?function |\n}\s*$)/s', $source, $m);

    return $m[0] ?? '';
}

it('only lets RoomRequestApprovalService approve or reject a room request', function () {
    // Confirming or staff-cancelling a request happens in exactly two places…
    expect(g5FilesMatching('/[\'"]status[\'"]\s*=>\s*GuestRequest::STATUS_(CONFIRMED|CANCELLED_BY_STAFF)/'))->toBe([
        'app/Services/Guest/GuestRequestService.php',
        'app/Services/Guest/RoomRequestApprovalService.php',
    ]);

    // …and the table side refuses room requests before it writes anything.
    $tables = file_get_contents(app_path('Services/Guest/GuestRequestService.php'));
    foreach (['confirm', 'cancelByStaff', 'reacceptReturned'] as $method) {
        $body = g5Method($tables, $method);
        expect($body)->not->toBe('');

        if ($method !== 'reacceptReturned') {
            expect(strpos($body, '->isRoom()'))->toBeLessThan(strpos($body, '->update('), "{$method}() must refuse room requests first");
        }
    }

    // Nothing but the Room Orders page drives the approval service.
    expect(g5FilesMatching('/RoomRequestApprovalService\)?\s*->\s*(approve|reject)\s*\(/'))->toBe(['app/Filament/Pages/RoomOrders.php']);
});

it('only lets RoomDeliveryService write delivery_status and delivery refusals', function () {
    expect(g5FilesMatching('/[\'"]delivery_status[\'"]\s*=>/'))->toBe(['app/Services/Guest/RoomDeliveryService.php']);
    expect(g5FilesMatching('/GuestDeliveryRefusal::[^;]*\b(create|forceCreate|insert|update|upsert)\s*\(|\$refusal\s*->\s*(update|save|fill|forceFill)\s*\(|[\'"]guest_delivery_refusals[\'"]\)[^;]*->\s*(insert|update)\s*\(/', ['app/Models/GuestDeliveryRefusal.php']))
        ->toBe(['app/Services/Guest/RoomDeliveryService.php']);
});

it('only lets RoomDeliveryService::decide() cancel a room order from guest code', function () {
    $cancels = '/RoomOrderService(\(\))?\)\s*->\s*cancel\s*\(|\$rooms?\s*->\s*cancel\s*\(/';

    $guestCode = collect(g5Sources())->filter(fn ($s, $path) => str_contains($path, 'Guest') || str_contains($path, 'guest')
        || str_contains($path, 'RoomOrders') || str_contains($path, 'room-orders'));

    expect($guestCode->filter(fn ($s) => preg_match($cancels, $s))->keys()->values()->all())
        ->toBe(['app/Services/Guest/RoomDeliveryService.php']);

    $delivery = file_get_contents(app_path('Services/Guest/RoomDeliveryService.php'));
    expect(preg_match($cancels, g5Method($delivery, 'decide')))->toBe(1);
    expect(preg_match_all($cancels, $delivery))->toBe(1);
});

it('never deletes a trusted device, a guest contact or a delivery refusal', function () {
    $deletes = '/(GuestTrustedDevice|GuestContact|GuestDeliveryRefusal)::[^;]*->\s*(delete|forceDelete|truncate)\s*\(|(GuestTrustedDevice|GuestContact|GuestDeliveryRefusal)::(destroy|truncate)\s*\(|[\'"](guest_trusted_devices|guest_contacts|guest_delivery_refusals)[\'"]\)[^;]*->\s*(delete|truncate)\s*\(|\$(contact|refusal|trusted)\s*->\s*(delete|forceDelete)\s*\(/';

    expect(g5FilesMatching($deletes))->toBe([]);

    foreach (['GuestTrustedDevice', 'GuestContact', 'GuestDeliveryRefusal'] as $model) {
        expect(file_get_contents(app_path("Models/{$model}.php")))->toContain('static::deleting(')->toContain('static::updating(');
    }
});
