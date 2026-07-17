<?php

namespace App\Console\Commands;

use App\Models\DailyBusinessSnapshot;
use App\Services\Ceo\DailyMetricsService;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Computes and stores one DailyBusinessSnapshot row per business date.
 * Idempotent by default — a date that already has a snapshot is skipped,
 * so the nightly schedule entry and a manual backfill can never collide
 * or duplicate a row. --force inserts a new, superseding row instead
 * (append-only correction — see DailyBusinessSnapshot's docblock), for
 * correcting a past day after e.g. a manager ruling changes an old
 * position.
 *
 * Refuses to snapshot the current (still-open) business day or any date
 * in the future — per Part A's own rule, "today" is always computed
 * live and never frozen into a snapshot row.
 */
class ComputeDailyBusinessSnapshot extends Command
{
    protected $signature = 'hms:compute-daily-snapshot
        {date? : A single business date (Y-m-d). Defaults to yesterday\'s business day.}
        {--from= : Start of a date range to backfill (inclusive)}
        {--to= : End of a date range to backfill (inclusive)}
        {--force : Insert a superseding row even if one already exists for the date}';

    protected $description = 'Compute and store the immutable daily business snapshot for one date or a range';

    public function handle(DailyMetricsService $metrics): int
    {
        foreach ($this->resolveDates() as $date) {
            $this->computeOne($date, $metrics);
        }

        return self::SUCCESS;
    }

    /** @return string[] */
    private function resolveDates(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from && $to) {
            $dates = [];
            $cursor = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to);

            while ($cursor->lte($end)) {
                $dates[] = $cursor->toDateString();
                $cursor = $cursor->addDay();
            }

            return $dates;
        }

        return [$this->argument('date') ?? BusinessDay::yesterday()];
    }

    private function computeOne(string $date, DailyMetricsService $metrics): void
    {
        if ($date >= BusinessDay::today()) {
            $this->warn("Skipping {$date} — the business day has not closed yet (today's business day is ".BusinessDay::today().'). The live dashboard covers in-progress figures.');

            return;
        }

        $existing = DailyBusinessSnapshot::latestFor($date);

        if ($existing && ! $this->option('force')) {
            $this->line("Skipping {$date} — snapshot already exists (use --force to recompute).");

            return;
        }

        $data = $metrics->forBusinessDate($date);

        if ($existing) {
            $data['supersedes_id'] = $existing->id;
        }

        DailyBusinessSnapshot::create($data);

        $this->info("Snapshot computed for {$date}".($existing ? " (superseding row #{$existing->id})" : '').'.');
    }
}
