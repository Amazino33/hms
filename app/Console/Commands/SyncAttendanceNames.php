<?php

namespace App\Console\Commands;

use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\ZktecoCommand;
use Illuminate\Console\Command;

/**
 * Asks the terminal to tell us the name enrolled against each badge.
 *
 * The device pushes USERINFO on its own only when somebody enrols or edits a
 * user on the keypad, so for badges enrolled before this feature existed the
 * names would otherwise never arrive. This queues one query per badge; the
 * device picks them up on its next poll (every ten seconds) and pushes the
 * answers back to /iclock/cdata, where ZKTecoController stores them.
 *
 * Queries are issued per PIN rather than as one bulk fetch: a wildcard query
 * is not something every firmware revision accepts, whereas DATA QUERY
 * USERINFO PIN=n is the best-documented form — and the set of PINs that have
 * ever punched is exactly the set of names worth showing.
 */
class SyncAttendanceNames extends Command
{
    protected $signature = 'hms:sync-attendance-names
        {--serial= : Only target the terminal with this serial number}
        {--all : Re-query every badge, including ones we already have a name for}';

    protected $description = 'Ask the ZKTeco terminal for the staff names enrolled against each machine ID';

    public function handle(): int
    {
        $badges = AttendanceLog::whereNotNull('biometric_id')
            ->distinct()
            ->pluck('biometric_id')
            ->merge(User::whereNotNull('biometric_id')->pluck('biometric_id'))
            ->unique()
            ->filter(fn ($id) => $id !== '')
            ->values();

        if ($badges->isEmpty()) {
            $this->warn('No machine IDs known yet — nothing to ask about.');
            $this->line('Badges appear here once the terminal has pushed at least one punch.');

            return self::SUCCESS;
        }

        if (! $this->option('all')) {
            $known = AttendanceDeviceUser::whereNotNull('device_name')->pluck('device_user_id');
            $badges = $badges->diff($known)->values();

            if ($badges->isEmpty()) {
                $this->info('Every known badge already has a name from the machine. Use --all to re-query.');

                return self::SUCCESS;
            }
        }

        $serial = $this->option('serial');

        foreach ($badges as $badge) {
            ZktecoCommand::create([
                'serial' => $serial,
                'command' => 'DATA QUERY USERINFO PIN='.$badge,
            ]);
        }

        $this->info('Queued '.$badges->count().' name lookup(s): '.$badges->implode(', '));
        $this->line('The terminal collects these on its next poll. Give it a minute, then reload Daily Attendance.');
        $this->line('If the Name on Machine column stays empty, run: php artisan hms:attendance-name-status');

        return self::SUCCESS;
    }
}
