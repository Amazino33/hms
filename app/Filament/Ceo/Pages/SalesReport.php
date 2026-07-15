<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Ceo\Concerns\ExportsCeoReports;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\OccupancyReportService;
use App\Services\Ceo\RevenueReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class SalesReport extends Page
{
    use ExportsCeoReports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Sales Report';

    protected string $view = 'filament-ceo.pages.sales-report';

    public string $dateFrom;

    public string $dateTo;

    public ?int $categoryId = null;

    public ?int $productId = null;

    public ?int $soldBy = null;

    public ?string $source = null;

    public ?string $billedVia = null;

    public function mount(): void
    {
        $this->dateFrom = CarbonImmutable::today()->startOfMonth()->toDateString();
        $this->dateTo = CarbonImmutable::today()->toDateString();
    }

    public function range(): DateRange
    {
        return new DateRange(CarbonImmutable::parse($this->dateFrom), CarbonImmutable::parse($this->dateTo));
    }

    private function filters(): array
    {
        $filters = [];

        if ($this->categoryId) {
            $filters['category_id'] = $this->categoryId;
        }

        if ($this->productId) {
            $filters['product_id'] = $this->productId;
        }

        if ($this->soldBy) {
            $filters['sold_by'] = $this->soldBy;
        }

        if ($this->source && $this->source !== 'rooms') {
            $filters['source'] = $this->source;
        }

        if ($this->billedVia) {
            $filters['billed_via_folio'] = $this->billedVia === 'folio';
        }

        return $filters;
    }

    public function isRoomsOnly(): bool
    {
        return $this->source === 'rooms';
    }

    public function productRows()
    {
        if ($this->isRoomsOnly()) {
            return collect();
        }

        $service = new RevenueReportService();

        return $service->productBreakdown($service->lineItems($this->range(), $this->filters()));
    }

    public function summary(): array
    {
        if ($this->isRoomsOnly()) {
            $roomRevenue = (new OccupancyReportService())->summary($this->range())['total_room_revenue'];

            return ['quantity' => 0, 'revenue' => $roomRevenue, 'cost' => 0.0, 'margin' => $roomRevenue, 'margin_pct' => 100.0];
        }

        $service = new RevenueReportService();

        return $service->summary($service->lineItems($this->range(), $this->filters()));
    }

    public function categories()
    {
        return Category::orderBy('name')->get();
    }

    public function products()
    {
        return Product::orderBy('name')->get();
    }

    public function waiters()
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['waiter', 'receptionist']))->orderBy('name')->get();
    }

    public function exportCsv()
    {
        return $this->csvResponse('sales-report.csv', [
            'Item', 'Category', 'Source', 'Quantity', 'Revenue', 'Cost', 'Margin', 'Margin %', 'Revenue Contribution %',
        ], $this->productRows()->map(fn ($r) => [
            $r['item_name'], $r['category_name'], $r['source'], $r['quantity'], $r['revenue'], $r['cost'], $r['margin'], $r['margin_pct'], $r['revenue_contribution_pct'],
        ]));
    }

    public function exportPdf()
    {
        $summary = $this->summary();

        return $this->pdfResponse('sales-report.pdf', 'Sales Report', $this->filtersDescription(), [
            'Total quantity' => number_format($summary['quantity']),
            'Total revenue' => '₦' . number_format($summary['revenue'], 2),
            'Total COGS' => '₦' . number_format($summary['cost'], 2),
            'Total margin' => '₦' . number_format($summary['margin'], 2) . ' (computed at current cost)',
            'Margin %' => number_format($summary['margin_pct'], 2) . '%',
        ], [
            'Item', 'Category', 'Source', 'Quantity', 'Revenue', 'Cost', 'Margin', 'Margin %', 'Revenue Contribution %',
        ], $this->productRows()->map(fn ($r) => [
            $r['item_name'], $r['category_name'], ucfirst($r['source']), number_format($r['quantity']), number_format($r['revenue'], 2),
            number_format($r['cost'], 2), number_format($r['margin'], 2), number_format($r['margin_pct'], 2), number_format($r['revenue_contribution_pct'], 2),
        ]));
    }

    private function filtersDescription(): string
    {
        return "{$this->dateFrom} to {$this->dateTo}"
            . ($this->categoryId ? ' | Category: ' . Category::find($this->categoryId)?->name : '')
            . ($this->source ? ' | Source: ' . ucfirst($this->source) : '')
            . ($this->billedVia ? ' | Billed via: ' . $this->billedVia : '');
    }
}
