<?php

/**
 * Phase 0C guard: folio lines are append-only. Nothing in app/ deletes one,
 * and the only code that updates one is FolioService resolving a transfer
 * payment (the narrow, model-enforced exception — see
 * FolioLine::PAYMENT_RESOLUTION_FIELDS). Charges are corrected only by a
 * reversal line. Migrations and seeders are outside app/ and not scanned.
 */
function appSourcesMentioning(string $needle): array
{
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && str_contains($source = file_get_contents($file->getPathname()), $needle)) {
            $hits[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = $source;
        }
    }

    return $hits;
}

it('never deletes a folio line anywhere in app/', function () {
    foreach (appSourcesMentioning('FolioLine') + appSourcesMentioning('folio_lines') as $path => $source) {
        expect(preg_match('/(FolioLine::|folio_lines|lines\(\))[^;]*->\s*(delete|forceDelete|truncate)\s*\(|FolioLine::destroy\s*\(/', $source))
            ->toBe(0, "{$path} deletes folio lines");
    }
});

it('only lets FolioService update a folio line, and only to resolve a transfer payment', function () {
    foreach (appSourcesMentioning('FolioLine') as $path => $source) {
        if ($path === 'app/Services/FolioService.php' || $path === 'app/Models/FolioLine.php') {
            continue;
        }

        expect(preg_match('/(FolioLine::|lines\(\))[^;]*->\s*update\s*\(|\$line->\s*(update|save)\s*\(/', $source))
            ->toBe(0, "{$path} updates a folio line");
    }

    // Inside FolioService, every update writes only payment-resolution fields.
    $service = file_get_contents(app_path('Services/FolioService.php'));
    preg_match_all('/\$line->update\(\[(.*?)\]\);/s', $service, $updates);

    expect($updates[1])->not->toBeEmpty();

    foreach ($updates[1] as $fields) {
        preg_match_all("/'([a-z_]+)'\s*=>/", $fields, $keys);
        expect(array_diff($keys[1], \App\Models\FolioLine::PAYMENT_RESOLUTION_FIELDS))->toBe([]);
    }
});

it('enforces immutability on the model itself', function () {
    $model = file_get_contents(app_path('Models/FolioLine.php'));

    expect($model)->toContain('static::updating(')->toContain('static::deleting(');
});
