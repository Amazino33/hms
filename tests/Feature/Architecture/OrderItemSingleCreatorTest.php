<?php

/**
 * Phase 0B guard: order lines are only ever created on the paths that also
 * handle their stock — OrderSplitter (every sale, through the inventory
 * pipeline) and the POS return ticket (stock moves when the bar/kitchen
 * confirms it). The floor plan's old JSON endpoint created lines with no
 * stock movement at all; this keeps a third creator from appearing again.
 */
it('creates order items only in OrderSplitter and the POS return ticket', function () {
    $pattern = '/OrderItem::(create|insert|firstOrCreate|updateOrCreate|make|forceCreate)\s*\(|new\s+OrderItem\b|items\(\)\s*->\s*(create|createMany|save|saveMany|make)\s*\(/';
    $creators = [];

    foreach ([app_path(), resource_path('views')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match($pattern, file_get_contents($file->getPathname()))) {
                $creators[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
    }

    sort($creators);

    expect($creators)->toBe([
        'app/Services/OrderSplitter.php',
        'resources/views/livewire/pos.blade.php',
    ]);
});

it('has no floor-plan controller left to add items through', function () {
    expect(file_exists(app_path('Http/Controllers/FloorPlanController.php')))->toBeFalse();
    expect(file_get_contents(base_path('routes/web.php')))->not->toContain('floor-plan/');
});
