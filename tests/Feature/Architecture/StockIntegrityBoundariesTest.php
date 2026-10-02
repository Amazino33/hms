<?php

/**
 * Phase 0F guards.
 */
function siSources(): array
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

it('only lets Mark Ready set an order to ready', function () {
    $setters = [];

    foreach (siSources() as $path => $source) {
        if (preg_match("/['\"]status['\"]\s*=>\s*['\"]ready['\"]|->status\s*=\s*['\"]ready['\"]/", $source)) {
            $setters[] = $path;
        }
    }

    expect($setters)->toBe([
        'app/Services/BarOrderService.php',      // bar Mark Ready (scoped to destination = bar), extracted from BarDisplay in Phase 5
        'app/Services/KitchenOrderService.php',  // THE kitchen Mark Ready
    ]);
});

it('only lets OrderObserver and the return flow record kitchen waste', function () {
    $writers = [];

    foreach (siSources() as $path => $source) {
        if (preg_match('/KitchenWasteLog::(create|insert|forceCreate|firstOrCreate|updateOrCreate|make)\s*\(|new\s+KitchenWasteLog\b|[\'"]kitchen_waste_logs[\'"]\)\s*->\s*insert/', $source)) {
            $writers[] = $path;
        }
    }

    expect($writers)->toBe([
        'app/Observers/OrderObserver.php',               // cooked order cancelled after Mark Ready
        'app/Services/ReturnConfirmationService.php',    // cooked dish returned
    ]);
});
