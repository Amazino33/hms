<?php

namespace App\Console\Commands;

use App\Models\BiometricEnrollment;
use App\Models\ZktecoCommand;
use App\Models\ZktecoDevice;
use App\Support\VenueTime;
use Illuminate\Console\Command;

/**
 * Shows whether the terminal is talking to us, and how the name sync is
 * getting on.
 *
 * Leads with proof of life because that is the question everything else
 * depends on: a device that is not connected cannot answer a name query, and
 * it cannot deliver punches either. Reads the zkteco_devices table rather
 * than the log, since production runs LOG_LEVEL=warning and drops every
 * info line we write.
 */
class AttendanceNameStatus extends Command
{
    protected $signature = 'hms:attendance-name-status';

    protected $description = 'Report whether the terminal is connected and answering our requests for staff names';

    public function handle(): int
    {
        $this->reportContact();
        $this->reportNames();
        $this->reportQueue();

        return self::SUCCESS;
    }

    private function reportContact(): void
    {
        $this->newLine();
        $this->info('Terminal contact');

        $devices = ZktecoDevice::orderBy('serial')->get();

        if ($devices->isEmpty()) {
            $this->warn('The terminal has not contacted this server since contact tracking was deployed.');
            $this->line('Until it does, nothing below can change. Check the device is powered on, on the');
            $this->line('venue network, and showing a green server icon.');

            return;
        }

        $this->table(
            ['Serial', 'Handshake', 'Command poll', 'Data push', 'Last punch', 'Punches'],
            $devices->map(fn (ZktecoDevice $d) => [
                $d->serial,
                $this->ago($d->last_handshake_at),
                $this->ago($d->last_poll_at),
                $this->ago($d->last_push_at),
                $this->ago($d->last_punch_at),
                $d->punches_received,
            ])->all()
        );

        $polled = $devices->max('last_poll_at');
        $pushed = $devices->max('last_push_at');

        if ($pushed !== null && $polled === null) {
            $this->warn('The device pushes data but never polls for commands, so it will not pick up');
            $this->line('name queries. Names can still arrive by re-saving a user on the keypad.');
        }
    }

    private function reportNames(): void
    {
        $names = BiometricEnrollment::orderBy('biometric_id')->get();

        $this->newLine();
        $this->info('Names received from the terminal');

        if ($names->isEmpty()) {
            $this->warn('None yet.');

            return;
        }

        $this->table(
            ['Machine ID', 'Name on machine', 'Last heard'],
            $names->map(fn (BiometricEnrollment $e) => [
                $e->biometric_id,
                $e->name ?? '— enrolled but unnamed —',
                $e->last_seen_at ? VenueTime::format($e->last_seen_at) : '—',
            ])->all()
        );
    }

    private function reportQueue(): void
    {
        $queued = ZktecoCommand::whereNull('sent_at')->count();
        $sent = ZktecoCommand::whereNotNull('sent_at')->whereNull('responded_at')->count();
        $answered = ZktecoCommand::whereNotNull('responded_at')->get();

        $this->newLine();
        $this->info('Request queue');
        $this->line("Waiting to be collected: {$queued}");
        $this->line("Collected, no answer yet: {$sent}");
        $this->line('Answered by the device:   '.$answered->count());

        $refused = $answered->filter(fn (ZktecoCommand $c) => $c->return_code !== null && $c->return_code !== '0');

        if ($refused->isNotEmpty()) {
            $this->newLine();
            $this->warn('The device refused '.$refused->count().' request(s) — this firmware may not support');
            $this->line('DATA QUERY USERINFO. Return codes seen: '.$refused->pluck('return_code')->unique()->implode(', '));
            $this->line('Fallback: re-save a user on the terminal keypad; that makes it push the name itself.');
        }
    }

    private function ago(?\DateTimeInterface $at): string
    {
        if ($at === null) {
            return 'never';
        }

        return VenueTime::format($at).' ('.\Carbon\Carbon::instance($at)->diffForHumans().')';
    }
}
