<?php

/**
 * Phase 3 guards for the guest flow.
 */
function g3Sources(): array
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

function g3FilesMatching(string $pattern, array $except = []): array
{
    return collect(g3Sources())
        ->filter(fn ($source, $path) => ! in_array($path, $except, true) && preg_match($pattern, $source))
        ->keys()
        ->values()
        ->all();
}

it('only lets the guest services pass a price override or the guest marker to OrderSplitter (D18)', function () {
    expect(g3FilesMatching('/unit_price_override|SOURCE_GUEST_REQUEST/', ['app/Services/OrderSplitter.php']))
        ->toBe(['app/Services/Guest/GuestRequestService.php']);
    // GuestBarReleaseService creates its orders through GuestRequestService::placeOrders().
    expect(file_get_contents(app_path('Services/Guest/GuestBarReleaseService.php')))->toContain('GuestRequestService::placeOrders(');
});

it('only lets GuestBarReleaseService record who released a drink', function () {
    // (GuestRequestItem only declares the datetime cast.)
    expect(g3FilesMatching("/['\"]released_by_user_id['\"]\s*=>|['\"]released_at['\"]\s*=>/", ['app/Models/GuestRequestItem.php']))
        ->toBe(['app/Services/Guest/GuestBarReleaseService.php']);
});

it('never lets a guest service write stock movements directly', function () {
    foreach (glob(app_path('Services/Guest/*.php')) as $path) {
        // Reading stock (GuestMenuService's availability) is fine; writing it is not.
        expect(preg_match('/(InventoryTransaction|IngredientTransaction|InventoryItem|IngredientInventoryItem)::(create|insert|forceCreate)|InventoryService::deduct|->\s*(increment|decrement)\s*\(/', file_get_contents($path)))
            ->toBe(0, basename($path).' touches stock directly');
    }
});

it('keeps the guest append-only logs append-only outside their writer', function () {
    expect(g3FilesMatching('/GuestSessionHandover::create\s*\(/'))->toBe(['app/Services/Guest/GuestSessionHandoverService.php']);
    expect(g3FilesMatching('/(BarShiftWaitLog|BarShiftConflictLog)::create\s*\(/'))->toBe(['app/Services/Guest/GuestBarMonitor.php']);

    $mutators = '/(GuestSessionHandover|BarShiftWaitLog|BarShiftConflictLog)::[^;]*->\s*(update|delete|forceDelete|truncate)\s*\(|(guest_session_handovers|bar_shift_wait_logs|bar_shift_conflict_logs)[\'"]\)[^;]*->\s*(update|delete|truncate)\s*\(/';
    expect(g3FilesMatching($mutators))->toBe([]);

    // The monitor's single close goes through the models' own guards.
    foreach (['GuestSessionHandover', 'BarShiftWaitLog', 'BarShiftConflictLog'] as $model) {
        expect(file_get_contents(app_path("Models/{$model}.php")))->toContain('static::updating(')->toContain('static::deleting(');
    }
});
