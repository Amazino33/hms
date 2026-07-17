<?php

namespace App\Filament\Ceo\Pages;

use App\Models\CountSession;
use App\Models\DamageReport;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\TransferDiscrepancy;
use App\Models\UnreturnableVoid;
use App\Services\Ceo\DailyMetricsService;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\DateRangeResolver;
use App\Services\Ceo\ExposureService;
use App\Services\Ceo\LeakageReportService;
use App\Services\Ceo\OccupancyReportService;
use App\Services\Ceo\RevenueReportService;
use App\Services\Ceo\StockAlertService;
use App\Support\BusinessDay;
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

        $todayLabel = CarbonImmutable::parse(BusinessDay::today());
        $trailing14 = new DateRange($todayLabel->subDays(13), $todayLabel);

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
            'owner' => $this->ownerData($range, $comparison),
        ];
    }

    /**
     * Part B's headline strip (profit / cash / gap), gap breakdown, trend
     * chart, and secondary panels — all sourced from DailyMetricsService's
     * rangeSeries() so these figures can never disagree with what the
     * nightly snapshot or the Report Explorer would show for the same
     * days (Part A rule 3).
     */
    private function ownerData(DateRange $range, ?DateRange $comparison): array
    {
        $metrics = new DailyMetricsService();
        $series = $metrics->rangeSeries($range);
        $comparisonSeries = $comparison ? $metrics->rangeSeries($comparison) : null;

        $profit = (float) $series->sum('gross_profit');
        $cash = (float) $series->sum('cash_collected_total');
        $comparisonProfit = $comparisonSeries ? (float) $comparisonSeries->sum('gross_profit') : null;
        $comparisonCash = $comparisonSeries ? (float) $comparisonSeries->sum('cash_collected_total') : null;

        // A trailing window of CLOSED days only, independent of the
        // selected range — today's gap is still accumulating as the day
        // goes on (a shift settles, a transfer gets verified), so it
        // would make the signal flicker constantly rather than reflect a
        // genuine multi-day trend. Ending at yesterday keeps every point
        // in the window a comparable, finished day.
        $yesterday = CarbonImmutable::parse(BusinessDay::yesterday());
        $trailingGap = $metrics->rangeSeries(new DateRange($yesterday->subDays(5), $yesterday))->pluck('gap_total')->values();

        $latestDay = $series->last();
        $expenses = (float) $series->sum('expenses_total');

        return [
            'profit' => [
                'value' => $profit,
                'delta' => $comparisonProfit !== null ? $this->resolver->delta($profit, $comparisonProfit) : null,
                'has_estimated' => $series->sum('cogs_estimated_count') > 0,
            ],
            'cash' => [
                'value' => $cash,
                'delta' => $comparisonCash !== null ? $this->resolver->delta($cash, $comparisonCash) : null,
                'cash' => (float) $series->sum('cash_collected_cash'),
                'pos' => (float) $series->sum('cash_collected_pos'),
                'transfers_verified' => (float) $series->sum('cash_collected_transfers_verified'),
                'transfers_unverified' => (float) $series->sum('cash_collected_transfers_unverified'),
            ],
            'gap' => [
                'value' => $latestDay['gap_total'] ?? 0.0,
                'as_of' => $latestDay['business_date'] ?? null,
                'widening' => $this->isWidening($trailingGap),
            ],
            'gap_breakdown' => [
                'unverified_transfers' => $latestDay['gap_unverified_transfers'] ?? 0.0,
                'open_folio_balance' => $latestDay['gap_open_folio_balance'] ?? 0.0,
                'unsettled_shift_amount' => $latestDay['gap_unsettled_shift_amount'] ?? 0.0,
                'staff_debt_outstanding' => $latestDay['gap_staff_debt_outstanding'] ?? 0.0,
            ],
            'trend_chart' => [
                'labels' => $series->map(fn (array $d) => CarbonImmutable::parse($d['business_date'])->format('M j'))->all(),
                'profit' => $series->pluck('gross_profit')->all(),
                'cash' => $series->pluck('cash_collected_total')->all(),
            ],
            'net_position' => [
                'value' => round($profit - $expenses, 2),
                // Shown for every range — daily is still shown, just
                // labeled "indicative" per the build spec, since lumpy
                // entries (e.g. a once-a-month salary run) can make a
                // single day look misleadingly bad or good; week/month
                // views are the real judgment call.
                'indicative' => $range->days() === 1,
            ],
            'expenses' => [
                'total' => $expenses,
                'top_categories' => $this->expenseTopCategories($range),
            ],
            'debts_damages' => [
                'new' => (float) $series->sum('staff_debt_new'),
                'repaid' => (float) $series->sum('staff_debt_repaid'),
                'outstanding' => $latestDay['gap_staff_debt_outstanding'] ?? 0.0,
                'damages_cost' => (float) $series->sum('damages_cost_total'),
                'pending_damage_approvals' => DamageReport::where('status', 'pending')->count(),
            ],
            'rooms' => [
                'occupancy_rate' => $latestDay['occupancy_rate'] ?? 0.0,
                'room_revenue' => (float) $series->sum('revenue_rooms'),
                'adr' => $latestDay['adr'] ?? 0.0,
                'open_folio_balance' => $latestDay['gap_open_folio_balance'] ?? 0.0,
            ],
        ];
    }

    /**
     * True when the gap has strictly increased on at least the last two
     * consecutive day-over-day comparisons in the trailing window — a
     * static gap is normal; a growing one is the alarm (per the build
     * spec). A short/empty window (too little history yet) never flags.
     */
    private function isWidening(\Illuminate\Support\Collection $trailingGap): bool
    {
        if ($trailingGap->count() < 3) {
            return false;
        }

        $last3 = $trailingGap->slice(-3)->values();

        return $last3[1] > $last3[0] && $last3[2] > $last3[1];
    }

    private function expenseTopCategories(DateRange $range): array
    {
        return Expense::notVoided()
            ->with('category')
            ->whereDate('date_incurred', '>=', $range->start->toDateString())
            ->whereDate('date_incurred', '<=', $range->end->toDateString())
            ->get()
            ->groupBy(fn (Expense $e) => $e->category?->name ?? 'Uncategorized')
            ->map(fn ($rows, $name) => ['name' => $name, 'total' => (float) $rows->sum('amount')])
            ->sortByDesc('total')
            ->take(5)
            ->values()
            ->all();
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
