<?php

namespace App\Console\Commands;

use App\Models\BiometricEnrollment;
use App\Models\ZktecoCommand;
use App\Support\VenueTime;
use Illuminate\Console\Command;

/**
 * Shows how the name sync is getting on.
 *
 * Whether a given K20 Pro firmware honours DATA QUERY USERINFO is not
 * something we can know from here, so this exists to make the answer
 * visible: queued but never collected means the device is not polling at
 * all; collected with a non-zero return means it refused the query; names
 * arriving means it worked.
 */
class AttendanceNameStatus extends Command
{
    protected $signature = 'hms:attendance-name-status';

    protected $description = 'Report whether the terminal is answering our requests for enrolled staff names';

    public function handle(): int
    {
        $names = BiometricEnrollment::orderBy('biometric_id')->get();

        $this->newLine();
        $this->info('Names received from the terminal');

        if ($names->isEmpty()) {
            $this->warn('None yet.');
        } else {
            $this->table(
                ['Machine ID', 'Name on machine', 'Last heard'],
                $names->map(fn (BiometricEnrollment $e) => [
                    $e->biometric_id,
                    $e->name ?? '— enrolled but unnamed —',
                    $e->last_seen_at ? VenueTime::format($e->last_seen_at) : '—',
                ])->all()
            );
        }

        $queued = ZktecoCommand::whereNull('sent_at')->count();
        $sent = ZktecoCommand::whereNotNull('sent_at')->whereNull('responded_at')->count();
        $answered = ZktecoCommand::whereNotNull('responded_at')->get();

        $this->newLine();
        $this->info('Request queue');
        $this->line("Waiting to be collected: {$queued}");
        $this->line("Collected, no answer yet: {$sent}");
        $this->line('Answered by the device:   '.$answered->count());

        if ($queued > 0 && $sent === 0 && $answered->isEmpty()) {
            $this->newLine();
            $this->warn('The terminal has not collected anything. It is not reaching /iclock/getrequest —');
            $this->line('check the device is online and showing a green server icon.');
        }

        $refused = $answered->filter(fn (ZktecoCommand $c) => $c->return_code !== null && $c->return_code !== '0');

        if ($refused->isNotEmpty()) {
            $this->newLine();
            $this->warn('The device refused '.$refused->count().' request(s) — this firmware may not support');
            $this->line('DATA QUERY USERINFO. Return codes seen: '.$refused->pluck('return_code')->unique()->implode(', '));
        }

        return self::SUCCESS;
    }
}
