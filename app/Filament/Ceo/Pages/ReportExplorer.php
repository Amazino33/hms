<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Ceo\Concerns\ExportsCeoReports;
use App\Models\DamageReport;
use App\Models\Expense;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ReportExport;
use App\Models\Room;
use App\Models\TransferDiscrepancy;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\DateRangeResolver;
use App\Services\Ceo\LeakageReportService;
use App\Services\Ceo\OccupancyReportService;
use App\Services\Ceo\RevenueReportService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The drill-down surface behind the Owner Dashboard's tap-through links —
 * six tabs sharing one range selector and one calculation layer
 * (RevenueReportService / OccupancyReportService / LeakageReportService,
 * the same services DailyMetricsService itself calls), so a figure here
 * can never disagree with the dashboard or a snapshot for the same day.
 *
 * Read-only: every export logs to report_exports (optional, per spec) and
 * never touches any operational table — see the write-guard test.
 */
class ReportExplorer extends Page
{
    use ExportsCeoReports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $title = 'Report Explorer';

    protected string $view = 'filament-ceo.pages.report-explorer';

    #[Url]
    public string $tab = 'sales';

    #[Url]
    public string $preset = 'today';

    #[Url]
    public ?string $customFrom = null;

    #[Url]
    public ?string $customTo = null;

    public const TABS = ['sales', 'products', 'debts', 'expenses', 'rooms', 'damages'];

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'sales';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function range(): DateRange
    {
        return (new DateRangeResolver())->resolvePreset($this->preset, $this->customFrom, $this->customTo);
    }

    /**
     * Everything the active tab's view needs — computed only for the
     * active tab, not all six every render.
     */
    public function tabData(): array
    {
        $range = $this->range();

        return match ($this->tab) {
            'sales' => $this->salesData($range),
            'products' => $this->productsData($range),
            'debts' => $this->debtsData($range),
            'expenses' => $this->expensesData($range),
            'rooms' => $this->roomsData($range),
            'damages' => $this->damagesData($range),
            default => [],
        };
    }

    // ── Sales ──────────────────────────────────────────────────────

    private function salesData(DateRange $range): array
    {
        $service = new RevenueReportService();
        $lineItems = $service->lineItems($range);

        return [
            'mix' => $service->revenueMix($range),
            'payment_mix' => $service->paymentMixSeries($range),
            'daily' => $service->dailyRevenueSeries($range),
            'rows' => $lineItems->sortByDesc('date')->values(),
        ];
    }

    // ── Products ───────────────────────────────────────────────────

    private function productsData(DateRange $range): array
    {
        $service = new RevenueReportService();
        $lineItems = $service->lineItems($range);
        $breakdown = $service->productBreakdown($lineItems);

        $fastMoversByUnits = $breakdown->sortByDesc('quantity')->take(10)->values();
        $fastMoversByMargin = $breakdown->sortByDesc('margin')->take(10)->values();

        return [
            'fast_movers_by_units' => $fastMoversByUnits,
            'fast_movers_by_margin' => $fastMoversByMargin,
            'slow_movers' => $this->slowMovers($range),
            'days_of_stock' => $this->daysOfStock($range),
            'shrinkage_prone' => $this->shrinkageProne($range),
        ];
    }

    /**
     * Products with stock on hand but zero or near-zero sales in range —
     * cash frozen on the shelf. Aggregated in SQL (grouped sale-quantity
     * sums), not by loading every transaction into PHP.
     */
    private function slowMovers(DateRange $range, float $nearZeroThreshold = 2): Collection
    {
        $soldQuantities = InventoryTransaction::query()
            ->where('type', 'sale')
            ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->selectRaw('product_id, SUM(quantity) as sold_qty')
            ->groupBy('product_id')
            ->pluck('sold_qty', 'product_id');

        return Product::query()
            ->where('is_active', true)
            ->with('inventory')
            ->get()
            ->map(function (Product $p) use ($soldQuantities) {
                $stock = (float) $p->inventory->sum('quantity');
                $sold = (float) ($soldQuantities[$p->id] ?? 0);

                return ['product' => $p, 'stock_on_hand' => $stock, 'sold_in_range' => $sold, 'stock_value_at_cost' => round($stock * (float) $p->cost_price, 2)];
            })
            ->filter(fn ($r) => $r['stock_on_hand'] > 0 && $r['sold_in_range'] <= $nearZeroThreshold)
            ->sortByDesc('stock_value_at_cost')
            ->values();
    }

    private function daysOfStock(DateRange $range, float $thresholdDays = 5): Collection
    {
        $days = max(1, $range->days());

        $soldQuantities = InventoryTransaction::query()
            ->where('type', 'sale')
            ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->selectRaw('product_id, SUM(quantity) as sold_qty')
            ->groupBy('product_id')
            ->pluck('sold_qty', 'product_id');

        return Product::query()
            ->where('is_active', true)
            ->with('inventory')
            ->get()
            ->map(function (Product $p) use ($soldQuantities, $days) {
                $stock = (float) $p->inventory->sum('quantity');
                $velocity = (float) ($soldQuantities[$p->id] ?? 0) / $days;

                return ['product' => $p, 'stock_on_hand' => $stock, 'daily_velocity' => round($velocity, 2), 'days_of_stock' => $velocity > 0 ? round($stock / $velocity, 1) : null];
            })
            ->filter(fn ($r) => $r['days_of_stock'] !== null && $r['days_of_stock'] < $thresholdDays)
            ->sortBy('days_of_stock')
            ->values();
    }

    /**
     * Ranked by combined appearance in shortage adjustments (missing
     * TransferDiscrepancy lines) and damage write-offs over the range.
     */
    private function shrinkageProne(DateRange $range): Collection
    {
        $damages = InventoryTransaction::query()
            ->where('type', 'damage_write_off')
            ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->selectRaw('product_id, COUNT(*) as damage_count, SUM(quantity) as damage_qty')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $shortages = TransferDiscrepancy::query()
            ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->whereHas('stockTransferItem')
            ->with('stockTransferItem')
            ->get()
            ->groupBy(fn (TransferDiscrepancy $d) => $d->stockTransferItem?->product_id)
            ->map(fn ($rows) => ['count' => $rows->count(), 'qty' => $rows->sum('missing_base_qty')]);

        $productIds = collect($damages->keys())->merge($shortages->keys())->unique()->filter();

        return Product::query()->whereIn('id', $productIds)->get()
            ->map(function (Product $p) use ($damages, $shortages) {
                $damage = $damages->get($p->id);
                $shortage = $shortages->get($p->id);

                return [
                    'product' => $p,
                    'damage_count' => $damage->damage_count ?? 0,
                    'damage_qty' => (float) ($damage->damage_qty ?? 0),
                    'shortage_count' => $shortage['count'] ?? 0,
                    'shortage_qty' => (float) ($shortage['qty'] ?? 0),
                    'incidents' => ($damage->damage_count ?? 0) + ($shortage['count'] ?? 0),
                ];
            })
            ->sortByDesc('incidents')
            ->values();
    }

    // ── Debts ──────────────────────────────────────────────────────

    private function debtsData(DateRange $range): array
    {
        $service = new LeakageReportService();

        return [
            'summary' => $service->summary($range),
            'aging' => $service->currentAgingBreakdown(),
            'rows' => $service->perStaffRows($range),
            'daily' => $this->dailyDebtMovements($range),
        ];
    }

    /**
     * New debt incurred and repayments made per day in range — the
     * closest true "over time" series available without walking the
     * full debt history to reconstruct a running outstanding balance for
     * every day (the aging card already covers the current position).
     */
    private function dailyDebtMovements(DateRange $range): Collection
    {
        $new = \App\Models\StaffDebt::whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->selectRaw('DATE(created_at) as d, SUM(amount) as total')->groupBy('d')->pluck('total', 'd');

        $repaid = \App\Models\StaffDebtRepayment::whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->selectRaw('DATE(created_at) as d, SUM(amount) as total')->groupBy('d')->pluck('total', 'd');

        return collect($range->eachDate())->map(fn ($date) => [
            'date' => $date,
            'new' => (float) ($new[$date->toDateString()] ?? 0),
            'repaid' => (float) ($repaid[$date->toDateString()] ?? 0),
        ]);
    }

    // ── Expenses ───────────────────────────────────────────────────

    private function expensesData(DateRange $range): array
    {
        $all = Expense::with(['category', 'enteredBy'])
            ->whereDate('date_incurred', '>=', $range->start->toDateString())
            ->whereDate('date_incurred', '<=', $range->end->toDateString())
            ->orderByDesc('date_incurred')
            ->get();

        $notVoided = $all->whereNull('voided_at');

        return [
            'rows' => $all,
            'total' => (float) $notVoided->sum('amount'),
            'by_category' => $notVoided->groupBy(fn (Expense $e) => $e->category?->name ?? 'Uncategorized')
                ->map(fn ($rows, $name) => ['name' => $name, 'total' => (float) $rows->sum('amount')])
                ->sortByDesc('total')
                ->values(),
            'by_day' => $notVoided->groupBy(fn (Expense $e) => $e->date_incurred->toDateString())
                ->map(fn ($rows, $date) => ['date' => $date, 'total' => (float) $rows->sum('amount')])
                ->sortBy('date')
                ->values(),
        ];
    }

    // ── Rooms ──────────────────────────────────────────────────────

    private function roomsData(DateRange $range): array
    {
        $service = new OccupancyReportService();
        $breakdown = $service->nightlyBreakdown($range);

        $byRoom = collect();

        foreach (Room::orderBy('number')->get() as $room) {
            $roomSummary = $service->summary($range, $room->id);
            $byRoom->push(['room' => $room, 'nights_sold' => $roomSummary['room_nights_sold'], 'revenue' => $roomSummary['total_room_revenue']]);
        }

        return [
            'summary' => $service->summary($range),
            'nightly' => $breakdown,
            'by_room' => $byRoom->sortByDesc('nights_sold')->values(),
            'open_folios' => $this->openFolios(),
        ];
    }

    /**
     * Current in-house bookings with a non-zero folio balance — "as of
     * now", like every other current-state figure on this dashboard
     * (ExposureService::inHouseFolioBalances() sums the same set; this
     * lists it row by row for drill-down).
     */
    private function openFolios(): Collection
    {
        return \App\Models\Booking::where('status', 'checked_in')
            ->with(['folio', 'guest', 'room'])
            ->get()
            ->map(fn ($b) => ['booking' => $b, 'balance' => $b->folio ? $b->folio->balance() : 0.0])
            ->filter(fn ($r) => $r['balance'] != 0.0)
            ->sortByDesc('balance')
            ->values();
    }

    // ── Damages ────────────────────────────────────────────────────

    private function damagesData(DateRange $range): array
    {
        $approved = DamageReport::where('status', 'approved')
            ->whereBetween('resolved_at', [$range->startBoundary(), $range->endBoundary()])
            ->with(['product', 'ingredient', 'warehouse', 'reportedBy', 'resolvedBy'])
            ->get()
            ->map(function (DamageReport $d) {
                $costPerUnit = (float) ($d->product?->last_cost_price ?? $d->ingredient?->cost_per_unit ?? 0);

                return ['report' => $d, 'cost' => round((float) $d->quantity * $costPerUnit, 2)];
            });

        $rejected = DamageReport::where('status', 'rejected')
            ->whereBetween('resolved_at', [$range->startBoundary(), $range->endBoundary()])
            ->with(['product', 'ingredient', 'resolvedBy'])
            ->get();

        $pending = DamageReport::where('status', 'pending')->with(['product', 'ingredient', 'reportedBy'])->get();

        return [
            'approved' => $approved,
            'total_cost' => round($approved->sum('cost'), 2),
            'rejected' => $rejected,
            'pending' => $pending,
            'by_product' => $approved->groupBy(fn ($r) => $r['report']->itemName())
                ->map(fn ($rows, $name) => ['name' => $name, 'cost' => round($rows->sum('cost'), 2)])
                ->sortByDesc('cost')
                ->values(),
        ];
    }

    // ── Exports ────────────────────────────────────────────────────

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $this->tabData();
        [$headers, $rows] = $this->csvShape($data);

        $this->logExport('csv');

        return $this->csvResponse("report-explorer-{$this->tab}.csv", $headers, $rows);
    }

    public function exportPdf(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $this->tabData();
        [$headers, $rows] = $this->csvShape($data);

        $this->logExport('pdf');

        return $this->pdfResponse(
            "report-explorer-{$this->tab}.pdf",
            'Report Explorer — '.ucfirst($this->tab),
            "{$this->range()->start->toDateString()} to {$this->range()->end->toDateString()}",
            $this->pdfSummary($data),
            $headers,
            $rows
        );
    }

    private function logExport(string $format): void
    {
        ReportExport::record('explorer:'.$this->tab, $format, $this->range()->start->toDateString(), $this->range()->end->toDateString(), auth()->id());
    }

    private function csvShape(array $data): array
    {
        return match ($this->tab) {
            'sales' => [
                ['Item', 'Category', 'Source', 'Billed via Folio', 'Quantity', 'Revenue', 'Cost', 'Margin', 'Date'],
                $data['rows']->map(fn ($r) => [$r['item_name'], $r['category_name'], $r['source'], $r['billed_via_folio'] ? 'Yes' : 'No', $r['quantity'], $r['revenue'], $r['cost'], $r['margin'], $r['date']?->toDateString()]),
            ],
            'products' => [
                ['Item', 'Category', 'Quantity', 'Revenue', 'Margin'],
                $data['fast_movers_by_units']->map(fn ($r) => [$r['item_name'], $r['category_name'], $r['quantity'], $r['revenue'], $r['margin']]),
            ],
            'debts' => [
                ['Staff', 'Incurred Count', 'Incurred Amount', 'Repaid', 'Outstanding', 'Repayment %'],
                $data['rows']->map(fn ($r) => [$r['user_name'], $r['debts_incurred_count'], $r['debts_incurred_amount'], $r['repaid'], $r['outstanding'], $r['repayment_ratio_pct']]),
            ],
            'expenses' => [
                ['Date', 'Category', 'Amount', 'Note', 'Entered By', 'Voided'],
                $data['rows']->map(fn (Expense $e) => [$e->date_incurred->toDateString(), $e->category?->name, $e->amount, $e->note, $e->enteredBy?->name, $e->isVoided() ? 'Yes' : 'No']),
            ],
            'rooms' => [
                ['Room', 'Nights Sold', 'Revenue'],
                $data['by_room']->map(fn ($r) => [$r['room']->number, $r['nights_sold'], $r['revenue']]),
            ],
            'damages' => [
                ['Item', 'Quantity', 'Cost', 'Warehouse', 'Reported By', 'Resolved By', 'Resolved At'],
                $data['approved']->map(fn ($r) => [$r['report']->itemName(), $r['report']->quantity, $r['cost'], $r['report']->warehouse?->name, $r['report']->reportedBy?->name, $r['report']->resolvedBy?->name, $r['report']->resolved_at?->toDateTimeString()]),
            ],
            default => [[], collect()],
        };
    }

    private function pdfSummary(array $data): array
    {
        return match ($this->tab) {
            'sales' => ['Total revenue' => '₦'.number_format($data['mix']['total'], 2), 'Bar' => '₦'.number_format($data['mix']['bar'], 2), 'Restaurant' => '₦'.number_format($data['mix']['restaurant'], 2), 'Rooms' => '₦'.number_format($data['mix']['rooms'], 2)],
            'products' => ['Fast movers shown' => $data['fast_movers_by_units']->count(), 'Slow movers' => $data['slow_movers']->count(), 'Under days-of-stock threshold' => $data['days_of_stock']->count()],
            'debts' => ['Total incurred' => '₦'.number_format($data['summary']['total_incurred'], 2), 'Total repaid' => '₦'.number_format($data['summary']['total_repaid'], 2), 'Outstanding now' => '₦'.number_format($data['summary']['total_outstanding_now'], 2)],
            'expenses' => ['Total (non-voided)' => '₦'.number_format($data['total'], 2)],
            'rooms' => ['Occupancy' => number_format($data['summary']['average_occupancy_pct'], 2).'%', 'Room revenue' => '₦'.number_format($data['summary']['total_room_revenue'], 2), 'ADR' => '₦'.number_format($data['summary']['adr'], 2)],
            'damages' => ['Total cost (approved)' => '₦'.number_format($data['total_cost'], 2), 'Pending' => $data['pending']->count()],
            default => [],
        };
    }
}
