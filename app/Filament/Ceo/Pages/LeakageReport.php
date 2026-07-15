<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Ceo\Concerns\ExportsCeoReports;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\LeakageReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class LeakageReport extends Page
{
    use ExportsCeoReports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $title = 'Leakage Report';

    protected string $view = 'filament-ceo.pages.leakage-report';

    public string $dateFrom;

    public string $dateTo;

    public ?int $userId = null;

    public ?string $reason = null;

    public string $status = 'all';

    public ?int $expandedUserId = null;

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
        return array_filter([
            'user_id' => $this->userId,
            'reason' => $this->reason,
            'status' => $this->status,
        ]);
    }

    public function rows()
    {
        return (new LeakageReportService())->perStaffRows($this->range(), $this->filters());
    }

    public function summary(): array
    {
        return (new LeakageReportService())->summary($this->range(), $this->filters());
    }

    public function toggleExpand(int $userId): void
    {
        $this->expandedUserId = $this->expandedUserId === $userId ? null : $userId;
    }

    public function debtsFor(int $userId)
    {
        return (new LeakageReportService())->debtsForUser($userId, $this->range());
    }

    public function reasons()
    {
        return StaffDebt::query()->distinct()->pluck('reason')->filter()->sort()->values();
    }

    public function staff()
    {
        return User::whereHas('debts')->orderBy('name')->get();
    }

    public function exportCsv()
    {
        return $this->csvResponse('leakage-report.csv', [
            'Staff', 'Debts Incurred (Count)', 'Debts Incurred (Amount)', 'Repaid', 'Outstanding', '0-7d', '8-30d', '30+d', 'Repayment Ratio %',
        ], $this->rows()->map(fn ($r) => [
            $r['user_name'], $r['debts_incurred_count'], $r['debts_incurred_amount'], $r['repaid'], $r['outstanding'],
            $r['aging_0_7'], $r['aging_8_30'], $r['aging_30_plus'], $r['repayment_ratio_pct'],
        ]));
    }

    public function exportPdf()
    {
        $summary = $this->summary();

        return $this->pdfResponse('leakage-report.pdf', 'Leakage Report', $this->filtersDescription(), [
            'Total incurred (period)' => '₦' . number_format($summary['total_incurred'], 2),
            'Total repaid (period)' => '₦' . number_format($summary['total_repaid'], 2),
            'Total outstanding (as of now)' => '₦' . number_format($summary['total_outstanding_now'], 2),
            'Repayment ratio' => number_format($summary['repayment_ratio_pct'], 2) . '%',
        ], [
            'Staff', 'Debts Incurred (Count)', 'Debts Incurred (Amount)', 'Repaid', 'Outstanding', '0-7d', '8-30d', '30+d', 'Repayment Ratio %',
        ], $this->rows()->map(fn ($r) => [
            $r['user_name'], $r['debts_incurred_count'], number_format($r['debts_incurred_amount'], 2), number_format($r['repaid'], 2),
            number_format($r['outstanding'], 2), number_format($r['aging_0_7'], 2), number_format($r['aging_8_30'], 2),
            number_format($r['aging_30_plus'], 2), number_format($r['repayment_ratio_pct'], 2),
        ]));
    }

    private function filtersDescription(): string
    {
        return "{$this->dateFrom} to {$this->dateTo}"
            . ($this->userId ? ' | Staff: ' . User::find($this->userId)?->name : '')
            . ($this->reason ? ' | Source: ' . $this->reason : '')
            . ' | Status: ' . $this->status;
    }
}
