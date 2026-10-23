<?php

use Illuminate\Support\Facades\File;

/**
 * Staff are never deleted. User::booting() throws on every model delete,
 * but a query-builder delete — User::where(...)->delete(), User::destroy(),
 * DB::table('users')->delete() — never fires model events, so it would walk
 * straight past that guard and into the 31 cascading foreign keys.
 *
 * Grep-level, per statement, like the other architecture tests: it fails on
 * the line that is written, long before anything runs it.
 */
function massUserDeletionSourceFiles(): array
{
    $files = [];

    foreach ([app_path(), base_path('routes'), database_path('seeders')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = File::get($file->getPathname());
            }
        }
    }

    return $files;
}

it('finds the source files it is meant to be guarding', function () {
    expect(massUserDeletionSourceFiles())->not->toBeEmpty();
});

it('never deletes users by query builder, destroy() or raw table access', function () {
    $patterns = [
        // A statement starting at User:: that ends in a delete.
        '/\bUser::(?:where|whereIn|whereKey|query|whereNotNull|whereNull|role)\b[^;]*->(?:forceDelete|delete)\s*\(/s',
        '/\bUser::destroy\s*\(/',
        // Raw access to the users table that deletes.
        "/table\(\s*['\"]users['\"]\s*\)[^;]*->delete\s*\(/s",
        "/DB::delete\s*\([^;]*\busers\b/s",
        // Model deletes that skip events skip the guard too. Nothing in the
        // app uses either today, so any use at all is flagged.
        '/->deleteQuietly\s*\(/',
        '/\bUser::withoutEvents\s*\(/',
    ];

    $violations = [];

    foreach (massUserDeletionSourceFiles() as $path => $contents) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents)) {
                $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }
    }

    expect(array_values(array_unique($violations)))->toBe([]);
});
