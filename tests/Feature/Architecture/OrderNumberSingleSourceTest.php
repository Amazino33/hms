<?php

/**
 * Phase 0A guard: OrderNumberGenerator is the only thing that builds an
 * order number. Any other 'ORD-' (or 'RET-') literal in app code means a
 * second, collision-prone generator has crept back in.
 */
it('builds order numbers only in OrderNumberGenerator', function () {
    $generator = str_replace('\\', '/', app_path('Services/Orders/OrderNumberGenerator.php'));
    $offenders = [];

    foreach ([app_path(), resource_path('views')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if ($file->getExtension() !== 'php' || $path === $generator) {
                continue;
            }

            if (preg_match('/[\'"](ORD|RET)-/', file_get_contents($path))) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);
});
