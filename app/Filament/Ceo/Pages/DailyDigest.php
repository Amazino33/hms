<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Ceo\Concerns\ExportsCeoReports;
use App\Models\CountSession;
use App\Models\OrderPayment;
use App\Models\UnreturnableVoid;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\OccupancyReportService;
use App\Services\Ceo\RevenueReportService;
use App\Services\Ceo\WaiterLedgerService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class DailyDigest extends Page
{
    use ExportsCeoReports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $title = 'Daily Digest';

    protected string $view = 'filament-ceo.pages.daily-digest';

    public function range(): DateRange
    {
        $yesterday = CarbonImmutable::yesterday();

        return new DateRange($yesterday, $yesterday);
    }

    public function data(): array
    {
        $range = $this->range();
        $revenueSvc = new RevenueReportService();
        $occupancySvc = new OccupancyReportService();

        $mix = $revenueSvc->revenueMix($range);
        $occSummary = $occupancySvc->summary($range);
        $occDay = $occupancySvc->nightlyBreakdown($range)->first();

        $shortfalls = (float) \App\Models\StaffDebt::whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])->sum('amount');

        $newUnverifiedTransfers = OrderPayment::where('method', 'transfer')
            ->where('verified', false)
            ->whereBetween('paid_at', [$range->startBoundary(), $range->endBoundary()])
            ->count();

        $voids = UnreturnableVoid::whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])->get();

        $unsealedHandovers = CountSession::whereIn('type', ['bar_handover', 'kitchen_handover'])
            ->whereIn('status', ['counting', 'declared', 'pending_review'])
            ->where('opened_at', '<', now()->subHours(4))
            ->count();

        return [
            'range' => $range,
            'revenue_total' => $mix['total'],
            'revenue_mix' => $mix,
            'payment_mix' => $revenueSvc->paymentMixSeries($range)->first()['by_method'] ?? [],
            'shortfalls_incurred' => $shortfalls,
            'occupancy_pct' => $occSummary['average_occupancy_pct'],
            'arrivals' => $occDay['arrivals'] ?? 0,
            'departures' => $occDay['departures'] ?? 0,
            'new_unverified_transfers' => $newUnverifiedTransfers,
            'voids_count' => $voids->count(),
            'voids_value' => (float) $voids->sum('amount'),
            'unsealed_handovers' => $unsealedHandovers,
        ];
    }

    public function exportPdf()
    {
        $d = $this->data();

        return $this->pdfResponse('daily-digest-' . $d['range']->start->toDateString() . '.pdf', 'Daily Digest', $d['range']->start->format('l, F j, Y'), [
            'Total revenue' => '₦' . number_format($d['revenue_total'], 2),
            'Bar / Restaurant / Rooms' => '₦' . number_format($d['revenue_mix']['bar'], 2) . ' / ₦' . number_format($d['revenue_mix']['restaurant'], 2) . ' / ₦' . number_format($d['revenue_mix']['rooms'], 2),
            'Shortfalls incurred' => '₦' . number_format($d['shortfalls_incurred'], 2),
            'Occupancy %' => number_format($d['occupancy_pct'], 2) . '%',
            'Arrivals / Departures' => $d['arrivals'] . ' / ' . $d['departures'],
            'New unverified transfers' => $d['new_unverified_transfers'],
            'Voids' => $d['voids_count'] . ' (₦' . number_format($d['voids_value'], 2) . ')',
            'Unsealed handovers past expected time' => $d['unsealed_handovers'],
        ], ['Payment Method', 'Amount'], collect($d['payment_mix'])->map(fn ($amount, $method) => [ucfirst($method), number_format($amount, 2)]));
    }
}
