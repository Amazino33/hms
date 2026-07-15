<?php

namespace App\Filament\Ceo\Pages;

use App\Models\CountSession;
use App\Models\Shift;
use App\Models\TransferDiscrepancy;
use App\Models\UnreturnableVoid;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\DateRangeResolver;
use App\Services\Ceo\ExposureService;
use App\Services\Ceo\LeakageReportService;
use App\Services\Ceo\OccupancyReportService;
use App\Services\Ceo\RevenueReportService;
use App\Services\Ceo\StockAlertService;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament-ceo.pages.dashboard';

    protected static ?string $title = 'Executive Dashboard';

    public string $preset = 'today';

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public string $comparisonMode = 'off';

    public ?string $comparisonCustomFrom = null;

    public ?string $comparisonCustomTo = null;

    private DateRangeResolver $resolver;

    public function boot(): void
    {
        $this->resolver = new DateRangeResolver();
    }

    public function range(): DateRange
    {
        return $this->resolver->resolvePreset($this->preset, $this->customFrom, $this->customTo);
    }

    public function comparisonRange(): ?DateRange
    {
        if ($this->comparisonMode === 'off') {
            return null;
        }

        return $this->resolver->resolveComparison(
            $this->comparisonMode,
            $this->range(),
            $this->comparisonCustomFrom,
            $this->comparisonCustomTo
        );
    }

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;
    }

    public function setComparisonMode(string $mode): void
    {
        $this->comparisonMode = $mode;
    }

    /**
     * Everything the view needs, assembled fresh on every render — no
     * rollup infra at this venue's scale, per the build spec's
     * performance note.
     */
    public function dashboardData(): array
    {
        $range = $this->range();
        $comparison = $this->comparisonRange();

        $revenueSvc = new RevenueReportService();
        $occupancySvc = new OccupancyReportService();
        $exposureSvc = new ExposureService();
        $leakageSvc = new LeakageReportService();
        $stockSvc = new StockAlertService();

        $mix = $revenueSvc->revenueMix($range);
        $lineItems = $revenueSvc->lineItems($range);
        $summary = $revenueSvc->summary($lineItems);
        $occSummary = $occupancySvc->summary($range);

        $comparisonMix = $comparison ? $revenueSvc->revenueMix($comparison) : null;
        $comparisonOcc = $comparison ? $occupancySvc->summary($comparison) : null;
        $comparisonSummary = $comparison ? $revenueSvc->summary($revenueSvc->lineItems($comparison)) : null;

        $unequalLength = $comparison && $comparison->days() !== $range->days();

        $trailing14 = new DateRange(CarbonImmutable::today()->subDays(13), CarbonImmutable::today());

        return [
            'range' => $range,
            'comparison' => $comparison,
            'unequal_length' => $unequalLength,
            'tier1' => [
                'revenue' => [
                    'value' => $mix['total'],
                    'delta' => $comparisonMix ? $this->resolver->delta($mix['total'], $comparisonMix['total']) : null,
                    'sparkline' => $revenueSvc->dailyRevenueSeries($trailing14)->pluck('revenue')->all(),
                    'per_day_avg' => $unequalLength ? $this->resolver->perDayAverage($mix['total'], $range) : null,
                    'comparison_per_day_avg' => $unequalLength && $comparison
                        ? $this->resolver->perDayAverage($comparisonMix['total'], $comparison) : null,
                ],
                'margin_pct' => [
                    'value' => $summary['margin_pct'],
                    'delta' => $comparisonSummary ? $this->resolver->delta($summary['margin_pct'], $comparisonSummary['margin_pct']) : null,
                ],
                'occupancy_pct' => [
                    'value' => $occSummary['average_occupancy_pct'],
                    'delta' => $comparisonOcc ? $this->resolver->delta($occSummary['average_occupancy_pct'], $comparisonOcc['average_occupancy_pct']) : null,
                    'sparkline' => $occupancySvc->nightlyBreakdown($trailing14)->pluck('occupancy_pct')->all(),
                ],
                'exposure' => $exposureSvc->totalExposure(),
            ],
            'tier2' => [
                'unverified_transfers' => $exposureSvc->unverifiedTransfers(),
                'debt_aging' => $leakageSvc->currentAgingBreakdown(),
                'in_house_folio_balances' => $exposureSvc->inHouseFolioBalances(),
                'voids' => $this->voidsInPeriod($range),
                'discrepancy_resolutions' => $this->discrepancyResolutionsInPeriod($range),
                'stock' => array_merge($stockSvc->counts(), ['total_value_at_cost' => $stockSvc->totalStockValueAtCost()]),
                'unsealed_handovers' => $this->unsealedHandoversPastExpected(),
                'stale_shifts' => $this->staleShifts(),
            ],
            'tier3' => [
                'revenue_mix' => $mix,
                'comparison_revenue_mix' => $comparisonMix,
                'payment_mix_series' => $revenueSvc->paymentMixSeries($range),
                'top_products' => $revenueSvc->productBreakdown($lineItems)->take(10),
                'daily_revenue' => $revenueSvc->dailyRevenueSeries($range),
                'daily_revenue_comparison' => $comparison ? $revenueSvc->dailyRevenueSeries($comparison) : null,
            ],
        ];
    }

    private function voidsInPeriod(DateRange $range): array
    {
        $voids = UnreturnableVoid::whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])->get();

        return ['count' => $voids->count(), 'value' => (float) $voids->sum('amount')];
    }

    private function discrepancyResolutionsInPeriod(DateRange $range): array
    {
        $resolved = TransferDiscrepancy::whereIn('status', ['reversed_to_store', 'written_off_missing'])
            ->whereBetween('resolved_at', [$range->startBoundary(), $range->endBoundary()])
            ->get();

        return [
            'count' => $resolved->count(),
            'value' => (float) $resolved->sum(fn (TransferDiscrepancy $d) => $d->missing_base_qty * $this->discrepancyUnitCost($d)),
        ];
    }

    private function discrepancyUnitCost(TransferDiscrepancy $d): float
    {
        if ($d->stockTransferItem?->product) {
            return (float) $d->stockTransferItem->product->cost_price;
        }

        if ($d->ingredientTransferItem?->ingredient) {
            return (float) $d->ingredientTransferItem->ingredient->cost_per_unit;
        }

        return 0.0;
    }

    private function unsealedHandoversPastExpected(): int
    {
        return CountSession::whereIn('type', ['bar_handover', 'kitchen_handover'])
            ->whereIn('status', ['counting', 'declared', 'pending_review'])
            ->where('opened_at', '<', now()->subHours(4))
            ->count();
    }

    private function staleShifts(): int
    {
        return Shift::active()->get()->filter(fn (Shift $s) => $s->isStale())->count();
    }
}
