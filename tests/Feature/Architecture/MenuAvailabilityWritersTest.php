<?php

/**
 * Phase 1A guards: the availability log is append-only, and "sold out" has
 * exactly two writers — the admin menu-item form and the KDS toggle
 * service — both on menu_items.available_for_sale.
 */
function maAppFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('never updates or deletes menu_item_availability_logs anywhere in app/', function () {
    foreach (maAppFiles() as $path => $source) {
        $mentionsLog = str_contains($source, 'MenuItemAvailabilityLog') || str_contains($source, 'menu_item_availability_logs');

        if (! $mentionsLog || $path === 'app/Models/MenuItemAvailabilityLog.php') {
            continue;
        }

        expect(preg_match('/(MenuItemAvailabilityLog::|menu_item_availability_logs|availabilityLogs\(\))[^;]*->\s*(update|delete|forceDelete|truncate|save|increment|decrement)\s*\(|MenuItemAvailabilityLog::destroy\s*\(/', $source))
            ->toBe(0, "{$path} modifies availability log rows");
    }
});

it('writes available_for_sale only from the admin form and the KDS toggle service', function () {
    $writers = [];

    foreach (maAppFiles() as $path => $source) {
        // Writes: an array key being assigned, a property assignment, or a form field bound to it.
        if (preg_match("/'available_for_sale'\s*=>|->available_for_sale\s*=[^=]|Toggle::make\('available_for_sale'\)/", $source)) {
            $writers[] = $path;
        }
    }

    sort($writers);

    expect($writers)->toBe([
        'app/Filament/Resources/MenuItems/MenuItemResource.php', // the admin form
        'app/Models/MenuItem.php',                               // the boolean cast, not a write
        'app/Services/MenuAvailabilityService.php',              // the KDS toggle
    ]);

    // The model entry is only the cast declaration.
    expect(file_get_contents(app_path('Models/MenuItem.php')))->toContain("'available_for_sale' => 'boolean'");
});
