<?php

/**
 * Phase 1B guards. Guest URLs name a place only by its random qr_token —
 * a URL built from a table id or number would let anyone walk the
 * venue — so App\Support\GuestUrl is the only code that builds one. And
 * the QR regeneration log is append-only.
 */
function guSources(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = file_get_contents($file->getPathname());
            }
        }
    }

    return $files;
}

it('builds guest URLs only in GuestUrl, and only from a qr_token', function () {
    $builders = [];

    foreach (guSources([app_path(), resource_path('views')]) as $path => $source) {
        if (preg_match("#['\"]/m/|route\(\s*['\"]guest\.place#", $source)) {
            $builders[] = $path;
        }
    }

    expect($builders)->toBe(['app/Support/GuestUrl.php']);

    // GuestUrl itself takes a token string, and every caller hands it a qr_token.
    expect(file_get_contents(app_path('Support/GuestUrl.php')))->toContain('public static function forToken(string $token)');

    foreach (guSources([app_path(), resource_path('views')]) as $path => $source) {
        preg_match_all('/GuestUrl::forToken\(([^)]*)\)/', $source, $calls);

        foreach ($calls[1] as $argument) {
            expect(str_contains($argument, 'qr_token'))->toBeTrue("{$path} builds a guest URL from something other than a qr_token");
        }
    }
});

it('never updates or deletes qr_token_regenerations anywhere in app/', function () {
    foreach (guSources([app_path()]) as $path => $source) {
        if ($path === 'app/Models/QrTokenRegeneration.php') {
            continue;
        }

        expect(preg_match('/(QrTokenRegeneration::|qr_token_regenerations)[^;]*->\s*(update|delete|forceDelete|truncate|save|increment)\s*\(|QrTokenRegeneration::destroy\s*\(/', $source))
            ->toBe(0, "{$path} modifies QR regeneration log rows");
    }
});
