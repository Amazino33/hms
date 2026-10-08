<?php

namespace App\Console\Commands;

use App\Models\CountSession;
use App\Services\CountBreakdownSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the movement breakdown for counts sealed before breakdowns were
 * captured at the seal, from the records still in the database. Purely
 * additive: it writes only to the breakdown tables (each rebuilt count is
 * marked reconstructed_at), never to stock, debts or the counts
 * themselves. Counts that already have a breakdown are skipped, so it is
 * safe to run more than once.
 */
class BackfillCountBreakdowns extends Command
{
    protected $signature = 'hms:backfill-count-breakdowns
        {--session=* : Only these count session ids (repeatable)}
        {--dry-run : List what would be rebuilt, write nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Rebuild the movement breakdown for counts sealed before breakdowns were captured';

    public function handle(CountBreakdownSnapshotService $snapshots): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('session')));

        // Oldest first, so each rebuilt count can lean on the one before it
        // for its brought-forward figure and repeat-shortage history.
        $sessions = CountSession::query()
            ->with('warehouse')
            ->where('status', 'reviewed')
            ->whereNotNull('reviewed_at')
            ->whereDoesntHave('breakdown')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('reviewed_at')
            ->get();

        if ($sessions->isEmpty()) {
            $this->info('Nothing to rebuild — every sealed count'.($ids ? ' you named' : '').' already has a breakdown.');

            return self::SUCCESS;
        }

        $this->line("Sealed counts without a breakdown: {$sessions->count()}");
        $this->table(
            ['Count', 'Sealed', 'Location', 'Type', 'Items'],
            $sessions->map(fn (CountSession $s) => [
                "#{$s->id}",
                $s->reviewed_at?->venueTime()->format('M j, Y g:i A'),
                $s->warehouse?->name ?? '—',
                $s->type,
                $s->items()->count(),
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->line('Each will get a breakdown rebuilt from the records, marked as rebuilt. Stock, debts and the counts themselves are not touched.');

        if (! $this->option('force') && ! $this->confirm('Proceed?')) {
            $this->info('Cancelled — nothing was written.');

            return self::SUCCESS;
        }

        $rebuilt = 0;
        $failed = 0;

        // Load each count fresh and let it go afterwards. Holding every
        // count with its items, products and movements in memory at once
        // ran past PHP's 128 MB limit on a live system with 150+ counts.
        $sessionIds = $sessions->pluck('id')->all();
        unset($sessions);

        foreach ($sessionIds as $sessionId) {
            try {
                DB::transaction(fn () => $snapshots->reconstruct(CountSession::findOrFail($sessionId)));
                $rebuilt++;
                $this->line("  ✓ #{$sessionId}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ #{$sessionId}: {$e->getMessage()}");
            }

            gc_collect_cycles();
        }

        $this->newLine();
        $this->info("Rebuilt {$rebuilt}".($failed ? ", failed {$failed}" : '').'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
