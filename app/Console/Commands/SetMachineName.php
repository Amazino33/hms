<?php

namespace App\Console\Commands;

use App\Models\Attendance\AttendanceDeviceUser;
use App\Services\Attendance\DeviceUserReconciler;
use Illuminate\Console\Command;

/**
 * Types a machine name in by hand, for when the terminal will not give it up.
 *
 * The device only volunteers a name when someone enrols or edits a user on
 * the keypad, and it only answers a name query if it is connected and its
 * firmware honours the query — neither of which can be forced from here. This
 * is the escape hatch: read the names off the terminal's own user list and
 * enter them once.
 *
 * A name set this way is stored in exactly the same place a pushed one is, so
 * a later push from the device simply overwrites it with the authoritative
 * value. Nothing to undo.
 */
class SetMachineName extends Command
{
    protected $signature = 'hms:set-machine-name
        {pairs?* : Machine ID and name pairs, e.g. 7 "Mary Clement" 20 "Chidi Okeke"}';

    protected $description = 'Set the Name on Machine for one or more machine IDs by hand';

    public function handle(): int
    {
        $pairs = $this->argument('pairs');

        if (empty($pairs)) {
            return $this->interactive();
        }

        if (count($pairs) % 2 !== 0) {
            $this->error('Expected machine ID and name in pairs — got an odd number of values.');
            $this->line('Quote names that contain spaces: php artisan hms:set-machine-name 7 "Mary Clement"');

            return self::FAILURE;
        }

        foreach (array_chunk($pairs, 2) as [$badge, $name]) {
            $this->store($badge, $name);
        }

        return self::SUCCESS;
    }

    /**
     * Walk every badge that has punched but has no name yet, which is the
     * realistic way to fill in a whole terminal's worth in one sitting.
     */
    private function interactive(): int
    {
        $missing = \App\Models\AttendanceLog::whereNotNull('biometric_id')
            ->distinct()
            ->pluck('biometric_id')
            ->diff(AttendanceDeviceUser::whereNotNull('device_name')->pluck('device_user_id'))
            ->sort()
            ->values();

        if ($missing->isEmpty()) {
            $this->info('Every machine ID that has punched already has a name.');

            return self::SUCCESS;
        }

        $this->info($missing->count().' machine ID(s) have no name yet.');
        $this->line('Press Enter on any of them to skip it.');
        $this->newLine();

        foreach ($missing as $badge) {
            $name = $this->ask("Name on machine for ID {$badge}");

            if (filled($name)) {
                $this->store($badge, $name);
            }
        }

        return self::SUCCESS;
    }

    private function store(string $badge, string $name): void
    {
        // Through the reconciler so a retired badge is refused here exactly
        // as it is for a device push — the manual path must not be a way
        // around the rule that a retired ID is finished with.
        DeviceUserReconciler::mirror($badge, trim($name));

        $this->line("  ID {$badge} → ".trim($name));
    }
}
