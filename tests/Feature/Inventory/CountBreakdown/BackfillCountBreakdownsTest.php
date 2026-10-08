<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\CountBreakdown;
use App\Models\CountBreakdownLine;
use App\Models\CountBreakdownMovement;
use App\Models\CountOpenOrder;
use App\Models\PagePermission;
use App\Services\CountBreakdownViewService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Wipe every breakdown, as if these counts were sealed before the update. */
function cbForgetBreakdowns(): void
{
    DB::table('count_open_orders')->delete();
    DB::table('count_breakdowns')->delete();
}

function cbLineFigures(int $sessionId): array
{
    return CountBreakdownLine::where('count_session_id', $sessionId)->get()
        ->map(fn ($l) => collect($l->getAttributes())->except(['id', 'count_breakdown_id', 'created_at', 'supersedes_id'])->all())
        ->all();
}

it('rebuilds an old count with the same figures a live capture gave, marked as rebuilt', function () {
    $c = cbFullScenario(25);
    $live = cbLineFigures($c['session']->id);
    $liveMovements = CountBreakdownLine::where('count_session_id', $c['session']->id)->first()->movements()->count();

    cbForgetBreakdowns();
    expect(CountBreakdown::count())->toBe(0);

    // Prices move on after the seal; the rebuild must use the cost as it was then.
    $c['product']->update(['price' => 5000, 'last_cost_price' => 4000]);

    $this->artisan('hms:backfill-count-breakdowns', ['--force' => true])
        ->expectsOutputToContain('Rebuilt 2')
        ->assertSuccessful();

    expect(cbLineFigures($c['session']->id))->toBe($live);

    $breakdown = CountBreakdown::where('count_session_id', $c['session']->id)->first();
    $line = $breakdown->lines()->first();

    expect($breakdown->isReconstructed())->toBeTrue()
        ->and((float) $line->variance_value_cost)->toBe(-1200.0)
        ->and($line->movements()->count())->toBe($liveMovements)
        ->and(CountOpenOrder::count())->toBe(0);

    foreach (CountBreakdownLine::FIGURE_COLUMNS as $figure => $column) {
        expect(round((float) $line->movements()->where('figure', $figure)->where('status', 'active')->sum('quantity'), 2))
            ->toBe(round((float) $line->{$column}, 2));
    }
});

it('only writes breakdown rows, skips counts that already have one, and writes nothing on a dry run', function () {
    $c = cbFullScenario(25);
    cbForgetBreakdowns();

    $this->artisan('hms:backfill-count-breakdowns', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
    expect(CountBreakdown::count())->toBe(0);

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\s+(into\s+)?"?(\w+)/i', $query->sql, $m)) {
            $writes[] = $m[3];
        }
    });

    $this->artisan('hms:backfill-count-breakdowns', ['--session' => [$c['session']->id], '--force' => true])->assertSuccessful();

    expect(array_values(array_unique($writes)))->each->toBeIn(['count_breakdowns', 'count_breakdown_lines', 'count_breakdown_movements'])
        ->and(CountBreakdown::pluck('count_session_id')->all())->toBe([$c['session']->id]);

    // Second run: the one already rebuilt is skipped, only the other is done.
    $this->artisan('hms:backfill-count-breakdowns', ['--force' => true])
        ->expectsOutputToContain('Rebuilt 1')
        ->assertSuccessful();
    $this->artisan('hms:backfill-count-breakdowns', ['--force' => true])
        ->expectsOutputToContain('Nothing to rebuild')
        ->assertSuccessful();
    expect(CountBreakdown::count())->toBe(2);
});

it('leaves unsealed counts alone', function () {
    $c = cbSetup(10);
    (new \App\Services\CountSessionService)->openSession('bar_handover', $c['bar']->id, $c['bartenderA']->id, $c['bartenderA']->id, $c['bartenderB']->id);

    $this->artisan('hms:backfill-count-breakdowns', ['--force' => true])
        ->expectsOutputToContain('Nothing to rebuild')
        ->assertSuccessful();
    expect(CountBreakdownMovement::count())->toBe(0);
});

it('labels a rebuilt count on the page and in the PDF', function () {
    $c = cbFullScenario(25);
    cbForgetBreakdowns();
    $this->artisan('hms:backfill-count-breakdowns', ['--force' => true])->assertSuccessful();
    PagePermission::firstOrCreate(['page_class' => CountSessionDetail::class, 'role_name' => 'bartender'], ['page_name' => 'Count Session Detail']);

    $payload = (new CountBreakdownViewService)->payload($c['session']->fresh(), false);
    expect($payload['recorded'])->toBeTrue()
        ->and($payload['reconstructed_at'])->not->toBeNull();

    Livewire::actingAs($c['bartenderA'])->test(CountSessionDetail::class, ['session_id' => $c['session']->id])
        ->assertSee('Rebuilt from records');

    expect(view('pdf.count-breakdown', ['d' => $payload])->render())->toContain('Rebuilt from records');
});
