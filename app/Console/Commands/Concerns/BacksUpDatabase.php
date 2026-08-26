<?php

namespace App\Console\Commands\Concerns;

/**
 * Shared by every command that destroys data in bulk — takes its own
 * mysqldump before anything is deleted, since these commands aren't only
 * ever run right before a deploy and can't rely on deploy.sh's own backup
 * step already having happened.
 */
trait BacksUpDatabase
{
    private function backupDatabase(string $prefix): string
    {
        $connection = config('database.connections.mysql');

        $backupDir = storage_path('backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir.'/'.$prefix.'_'.now()->format('Ymd_His').'.sql';

        $command = sprintf(
            'mysqldump -h %s -u %s -p%s %s > %s',
            escapeshellarg($connection['host']),
            escapeshellarg($connection['username']),
            escapeshellarg($connection['password']),
            escapeshellarg($connection['database']),
            escapeshellarg($backupFile),
        );

        exec($command, result_code: $exitCode);

        if ($exitCode !== 0 || ! file_exists($backupFile) || filesize($backupFile) === 0) {
            throw new \RuntimeException('Backup failed — aborting before any data was deleted. Check that mysqldump is installed and reachable.');
        }

        return $backupFile;
    }
}
