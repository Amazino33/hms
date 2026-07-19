<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Ceo\Concerns\ExportsCeoReports;
use App\Models\User;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\WaiterLedgerService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class WaiterLedger extends Page
{
    use ExportsCeoReports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $title = 'Waiter Ledger';

    protected string $view = 'filament-ceo.pages.waiter-ledger';

    public ?int $waiterId = null;

    public string $dateFrom;

    public string $dateTo;

    public string $mode = 'shifts';

    public function mount(): void
    {
        $this->dateFrom = CarbonImmutable::today()->startOfMonth()->toDateString();
        $this->dateTo = CarbonImmutable::today()->toDateString();
    }

    public function range(): DateRange
    {
        return new DateRange(CarbonImmutable::parse($this->dateFrom), CarbonImmutable::parse($this->dateTo));
    }

    public function waiters()
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'waiter'))->orderBy('name')->get();
    }

    public function shiftRows()
    {
        if (! $this->waiterId) {
            return collect();
        }

        return (new WaiterLedgerService())->perShiftRows($this->waiterId, $this->range());
    }

    public function summary(): array
    {
        if (! $this->waiterId) {
            return [];
        }

        return (new WaiterLedgerService())->summary($this->waiterId, $this->range());
    }

    public function allWaiterRows()
    {
        return (new WaiterLedgerService())->allWaiters($this->range());
    }

    public function orderRows()
    {
        if (! $this->waiterId) {
            return collect();
        }

        return (new WaiterLedgerService())->orderRows($this->waiterId, $this->range());
    }

    public function debtRows()
    {
        if (! $this->waiterId) {
            return collect();
        }

        return (new WaiterLedgerService())->debtRows($this->waiterId, $this->range());
    }

    public function exportCsv()
    {
        $filtersDesc = $this->filtersDescription();

        if ($this->mode === 'all_waiters') {
            return $this->csvResponse('waiter-ledger-all-waiters.csv', [
                'Waiter', 'Sales Handled', 'Commission Earned', 'Shortfall', 'Shortfall Rate %', 'Outstanding Debt',
            ], $this->allWaiterRows()->map(fn ($r) => [
                $r['waiter_name'], $r['sales_handled'], $r['commission_earned'], $r['shortfall'], $r['shortfall_rate_pct'], $r['outstanding_debt'],
            ]));
        }

        return $this->csvResponse('waiter-ledger.csv', [
            'Date', 'Orders', 'Total Sales', 'Commission', 'Cash Declared', 'POS Total', 'Transfer Total', 'Shortfall', 'Shortfall Rate %', 'Running Debt Balance',
        ], $this->shiftRows()->map(fn ($r) => [
            $r['date']->format('Y-m-d H:i'), $r['orders_count'], $r['total_sales'], $r['commission'], $r['cash_declared'],
            $r['pos_total'], $r['transfer_total'], $r['shortfall'], $r['shortfall_rate_pct'], $r['running_debt_balance'],
        ]));
    }

    public function exportPdf()
    {
        $summary = $this->summary();

        if ($this->mode === 'all_waiters') {
            return $this->pdfResponse('waiter-ledger-all-waiters.pdf', 'Waiter Ledger — All Waiters', $this->filtersDescription(), [], [
                'Waiter', 'Sales Handled', 'Commission Earned', 'Shortfall', 'Shortfall Rate %', 'Outstanding Debt',
            ], $this->allWaiterRows()->map(fn ($r) => [
                $r['waiter_name'], number_format($r['sales_handled'], 2), number_format($r['commission_earned'], 2), number_format($r['shortfall'], 2),
                number_format($r['shortfall_rate_pct'], 2), number_format($r['outstanding_debt'], 2),
            ]));
        }

        return $this->pdfResponse('waiter-ledger.pdf', 'Waiter Ledger', $this->filtersDescription(), [
            'Total sales handled' => '₦' . number_format($summary['total_sales_handled'] ?? 0, 2),
            'Total commission earned' => '₦' . number_format($summary['total_commission_earned'] ?? 0, 2),
            'Total shortfall' => '₦' . number_format($summary['total_shortfall'] ?? 0, 2),
            'Shortfall rate' => number_format($summary['shortfall_rate_pct'] ?? 0, 2) . '%',
            'Orders count' => $summary['orders_count'] ?? 0,
            'Average sale per order' => '₦' . number_format($summary['avg_sale_per_order'] ?? 0, 2),
            'Debt incurred in period' => '₦' . number_format($summary['debt_incurred_in_period'] ?? 0, 2),
            'Current outstanding debt balance' => '₦' . number_format($summary['current_outstanding_debt_balance'] ?? 0, 2),
        ], [
            'Date', 'Orders', 'Total Sales', 'Commission', 'Cash Declared', 'POS Total', 'Transfer Total', 'Shortfall', 'Shortfall Rate %', 'Running Debt Balance',
        ], $this->shiftRows()->map(fn ($r) => [
            $r['date']->format('Y-m-d H:i'), $r['orders_count'], number_format($r['total_sales'], 2), number_format($r['commission'], 2), number_format($r['cash_declared'], 2),
            number_format($r['pos_total'], 2), number_format($r['transfer_total'], 2), number_format($r['shortfall'], 2),
            number_format($r['shortfall_rate_pct'], 2), number_format($r['running_debt_balance'], 2),
        ]));
    }

    private function filtersDescription(): string
    {
        $waiterName = $this->waiterId ? User::find($this->waiterId)?->name : 'All Waiters';

        return "Waiter: {$waiterName} | {$this->dateFrom} to {$this->dateTo}";
    }
}
