<?php

use App\Filament\Ceo\Pages\ReportExplorer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The module's single hard invariant: this code may only ever write to
 * daily_business_snapshots and report_exports — nothing else, anywhere,
 * including via a page render or an export. Verified by listening to
 * every query Eloquent/the query builder actually issues while rendering
 * each surface, not by reading the code and hoping.
 */
function assertNoUnexpectedWrites(Closure $action): void
{
    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        $sql = strtolower($query->sql);
        $isWrite = str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete');

        if (! $isWrite) {
            return;
        }

        $allowed = str_contains($sql, 'daily_business_snapshots') || str_contains($sql, 'report_exports');

        if (! $allowed) {
            $writes[] = $query->sql;
        }
    });

    $action();

    expect($writes)->toBe([], 'Unexpected write(s) to non-snapshot/export tables: '.implode('; ', $writes));
}

beforeEach(function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $this->user = User::factory()->create();
    $this->user->assignRole('ceo');
});

it('performs no writes outside daily_business_snapshots/report_exports when rendering the dashboard', function () {
    assertNoUnexpectedWrites(function () {
        $this->actingAs($this->user)->get('/ceo')->assertSuccessful();
    });
});

it('performs no writes outside daily_business_snapshots/report_exports when rendering each explorer tab', function () {
    foreach (ReportExplorer::TABS as $tab) {
        assertNoUnexpectedWrites(function () use ($tab) {
            $this->actingAs($this->user)->get("/ceo/report-explorer?tab={$tab}")->assertSuccessful();
        });
    }
});

it('the CSV export writes only a report_exports log row, nothing else', function () {
    assertNoUnexpectedWrites(function () {
        Livewire::actingAs($this->user)->test(ReportExplorer::class)->call('exportCsv');
    });

    expect(\App\Models\ReportExport::count())->toBe(1);
});

it('the PDF export writes only a report_exports log row, nothing else', function () {
    assertNoUnexpectedWrites(function () {
        Livewire::actingAs($this->user)->test(ReportExplorer::class)->call('exportPdf');
    });

    expect(\App\Models\ReportExport::count())->toBe(1);
});
