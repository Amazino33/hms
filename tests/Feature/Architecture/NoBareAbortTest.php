<?php

/**
 * Part of the system-wide notification/silent-failure fix: a bare abort()
 * inside application/panel/kiosk code drops the user onto a blank framework
 * error page with no cause+remedy message — the exact "silent failure"
 * class this fix eliminates. The only approved use is the two hard
 * authentication gates in app/Http/Middleware/ (device registration, PIN
 * auth), which run before any Livewire/Filament UI exists to show a
 * notification in. Every other guard must go through a notification
 * (UserFeedback or Filament\Notifications\Notification), never abort().
 */
it('never calls bare abort() outside app/Http/Middleware/', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $normalized = str_replace('\\', '/', $path);

        if (str_contains($normalized, '/Http/Middleware/')) {
            continue;
        }

        $contents = file_get_contents($path);

        if (preg_match('/\babort\s*\(/', $contents)) {
            $offenders[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([]);
});
