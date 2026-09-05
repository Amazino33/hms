<?php

namespace App\Filament\Pages;

use App\Filament\Ceo\Concerns\ExportsCeoReports;
use App\Services\PermissionService;
use App\Services\StaffDebtReportService;
use App\Support\BusinessDay;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * "Print me everything Chidi and Ngozi still owe from last month, and say
 * which of it came out of a handover" — the downloadable staff-debt
 * ledger.
 *
 * The Staff Debts resource lists debts one screen at a time for acting on
 * (record a repayment, read the history). This page exists for the other
 * half of the job: pulling a filtered set out of the system as a file to
 * take to a payroll meeting or hand to the staff member being charged. So
 * it filters by business day, by any number of staff at once, and by
 * whether the debt fell out of a reconciliation or somebody entered it.
 */
class StaffDebtReport extends Page
{
    /**
     * Export plumbing only (fputcsv + the shared PDF briefing layout) —
     * despite living under Ceo\Concerns it holds nothing panel-specific,
     * and a second copy of it here would be the worse trade.
     */
    use ExportsCeoReports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static string|UnitEnum|null $navigationGroup = 'Management';

    protected static ?string $navigationLabel = 'Staff Debt Report';

    protected static ?string $title = 'Staff Debt Report';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.staff-debt-report';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, int|string> */
    public array $userIds = [];

    public string $staffSearch = '';

    public string $status = 'all';

    public string $origin = 'all';

    public function mount(): void
    {
        $this->dateTo = BusinessDay::today();
        $this->dateFrom = CarbonImmutable::parse($this->dateTo)->subDays(29)->toDateString();
    }

    public function clearStaff(): void
    {
        $this->userIds = [];
    }

    public function selectAllStaff(): void
    {
        $this->userIds = $this->staffOptions()->pluck('id')->all();
    }

    /**
     * Convenience windows over the business day, not the calendar day —
     * "this month" here means the trading nights of this month.
     */
    public function setRange(string $preset): void
    {
        $today = CarbonImmutable::parse(BusinessDay::today());

        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'week' => [$today->subDays(6), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth()],
            'quarter' => [$today->subDays(89), $today],
            default => [CarbonImmutable::parse($this->dateFrom), CarbonImmutable::parse($this->dateTo)],
        };

        $this->dateFrom = $from->toDateString();
        $this->dateTo = $to->toDateString();
    }

    private function service(): StaffDebtReportService
    {
        return new StaffDebtReportService;
    }

    /**
     * Per-request memos. #[Computed] only caches when read as properties,
     * and the view reaches for these as methods in several places — the
     * detail table, the per-staff rollup and the tiles would otherwise
     * each re-run the whole query. Private so Livewire never tries to
     * serialise them; a fresh instance per request is exactly the
     * invalidation wanted when a filter changes.
     */
    private ?Collection $rowsMemo = null;

    private ?Collection $perStaffMemo = null;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->rowsMemo ??= $this->service()->rows([
            'from' => $this->dateFrom ?: null,
            'to' => $this->dateTo ?: null,
            'user_ids' => $this->userIds,
            'status' => $this->status,
            'origin' => $this->origin,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        return $this->service()->summary($this->rows());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function perStaff(): Collection
    {
        return $this->perStaffMemo ??= $this->service()->perStaff($this->rows(), $this->unresolvedShortages());
    }

    private ?Collection $shortagesMemo = null;

    /**
     * Handover shortages nobody has ruled on yet. Hidden when the filters
     * are asking a question these can't answer — a settled-debts view or a
     * manually-recorded-only view — since an unruled shortage is neither.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function unresolvedShortages(): Collection
    {
        if ($this->origin === 'recorded' || in_array($this->status, ['settled', 'partially_settled'], true)) {
            return collect();
        }

        return $this->shortagesMemo ??= $this->service()->unresolvedShortages([
            'from' => $this->dateFrom ?: null,
            'to' => $this->dateTo ?: null,
            'user_ids' => $this->userIds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function shortageSummary(): array
    {
        return $this->service()->shortageSummary($this->unresolvedShortages());
    }

    /**
     * @return array{count: int, latest: ?string, outstanding: float}
     */
    #[Computed]
    public function outsideRange(): array
    {
        return $this->service()->outsideRange([
            'from' => $this->dateFrom ?: null,
            'to' => $this->dateTo ?: null,
            'user_ids' => $this->userIds,
            'status' => $this->status,
            'origin' => $this->origin,
        ]);
    }

    /**
     * Widens the window to cover every debt matching the other filters —
     * the one click that answers "is it really zero, or is it my range?"
     */
    public function showAllDates(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function staffOptions(): Collection
    {
        $staff = $this->service()->staffWithDebts();

        if (trim($this->staffSearch) === '') {
            return $staff;
        }

        return $staff->filter(
            fn ($user) => str_contains(strtolower($user->name), strtolower(trim($this->staffSearch)))
        )->values();
    }

    /**
     * Names of the people currently selected, so a long filtered checkbox
     * list can never hide who the figures on screen actually cover.
     */
    #[Computed]
    public function selectedStaffNames(): array
    {
        if ($this->userIds === []) {
            return [];
        }

        $selected = array_map('intval', $this->userIds);

        return $this->service()->staffWithDebts()
            ->whereIn('id', $selected)
            ->pluck('name')
            ->all();
    }

    public function isStaffSelected(int $id): bool
    {
        return in_array($id, array_map('intval', $this->userIds), true);
    }

    /**
     * Column headers for the everything-on-screen export.
     *
     * @return array<int, string>
     */
    private function combinedHeaders(): array
    {
        return [
            'Type', 'Business Day', 'Recorded At', 'Staff', 'Source', 'Detail', 'Shift / Session',
            'Debt Amount', 'Repaid', 'Outstanding', 'Unruled Shortage Value',
            'Status', 'Recorded By', 'Order #', 'Age (days)', 'Notes',
        ];
    }

    /**
     * Debts and unruled shortages in one list, because "download what I am
     * looking at" is the only reading of the export button anyone actually
     * has. Splitting them across separate files meant a screen showing ₦9m
     * of shortages handed back an empty CSV.
     *
     * They stay honest by living in different money columns rather than a
     * shared one: summing Outstanding still gives only real debt, and
     * summing Unruled Shortage Value gives only what is still unruled, so
     * neither total can quietly absorb the other.
     *
     * @return Collection<int, array<int, mixed>>
     */
    private function combinedExportRows(): Collection
    {
        $debts = $this->rows()->map(fn (array $r) => [
            'Debt',
            $r['business_day'],
            $r['created_at']?->format('Y-m-d H:i'),
            $r['staff_name'],
            $r['origin'] === 'handover' ? 'During handover' : 'Recorded by a person',
            $r['reason_label'],
            $r['shift_id'] ? '#'.$r['shift_id'].' ('.$r['shift_type'].')' : '',
            number_format($r['amount'], 2, '.', ''),
            number_format($r['repaid'], 2, '.', ''),
            number_format($r['outstanding'], 2, '.', ''),
            '',
            $r['status_label'],
            $r['recorded_by'],
            $r['order_number'] ?? '',
            $r['age_days'],
            $r['notes'] ?? '',
        ]);

        $shortages = $this->unresolvedShortages()->map(fn (array $r) => [
            'Unruled shortage',
            $r['business_day'],
            $r['created_at']?->format('Y-m-d H:i'),
            $r['staff_name'],
            'During handover',
            $r['item_name'],
            trim(($r['warehouse'] ?? '').($r['session_id'] ? ' session #'.$r['session_id'] : '')),
            '',
            '',
            '',
            number_format($r['value'], 2, '.', ''),
            $r['status_label'].' — not a debt yet',
            '',
            '',
            $r['age_days'],
            number_format($r['quantity'], 2).' short at ₦'.number_format($r['unit_price'], 2).' each',
        ]);

        return $debts->concat($shortages);
    }

    public function exportCsv(): StreamedResponse
    {
        return $this->csvResponse(
            'staff-debts-'.$this->rangeSlug().'.csv',
            $this->combinedHeaders(),
            $this->combinedExportRows()
        );
    }

    /**
     * The same two kinds of row, narrowed for print. All 16 CSV columns on
     * A4 landscape squeeze down to unreadable — a spreadsheet can scroll,
     * a sheet of paper cannot.
     *
     * @return array<int, string>
     */
    private function printHeaders(): array
    {
        return [
            'Type', 'Business Day', 'Staff', 'Detail', 'Where',
            'Debt Amount', 'Repaid', 'Outstanding', 'Unruled Shortage', 'Status',
        ];
    }

    /**
     * @return Collection<int, array<int, mixed>>
     */
    private function printRows(): Collection
    {
        $debts = $this->rows()->map(fn (array $r) => [
            'Debt',
            $r['business_day'],
            $r['staff_name'],
            $r['reason_label'],
            $r['shift_id'] ? 'Shift #'.$r['shift_id'] : ($r['order_number'] ? 'Order '.$r['order_number'] : '—'),
            number_format($r['amount'], 2),
            number_format($r['repaid'], 2),
            number_format($r['outstanding'], 2),
            '—',
            $r['status_label'],
        ]);

        $shortages = $this->unresolvedShortages()->map(fn (array $r) => [
            'Unruled shortage',
            $r['business_day'],
            $r['staff_name'],
            $r['item_name'].' ('.number_format($r['quantity'], 2).' short)',
            trim(($r['warehouse'] ?? '').($r['session_id'] ? ' #'.$r['session_id'] : '')) ?: '—',
            '—',
            '—',
            '—',
            number_format($r['value'], 2),
            $r['status_label'],
        ]);

        return $debts->concat($shortages);
    }

    /**
     * The per-staff rollup on its own — the sheet a payroll deduction or a
     * staff conversation actually runs off.
     */
    public function exportStaffSummaryCsv(): StreamedResponse
    {
        return $this->csvResponse(
            'staff-debts-per-staff-'.$this->rangeSlug().'.csv',
            [
                'Staff', 'Debts', 'Charged', 'Repaid', 'Outstanding', 'Still Pending',
                'Outstanding From Handovers', 'Outstanding Recorded By A Person',
                'Unruled Shortage Lines', 'Unruled Shortage Value',
                'Oldest Pending (days)', 'Last Debt On',
            ],
            $this->perStaff()->map(fn (array $r) => [
                $r['staff_name'],
                $r['debts'],
                number_format($r['charged'], 2, '.', ''),
                number_format($r['repaid'], 2, '.', ''),
                number_format($r['outstanding'], 2, '.', ''),
                $r['pending_count'],
                number_format($r['handover_outstanding'], 2, '.', ''),
                number_format($r['recorded_outstanding'], 2, '.', ''),
                $r['shortage_lines'],
                number_format($r['shortage_value'], 2, '.', ''),
                $r['oldest_pending_days'],
                $r['last_debt_on'],
            ])
        );
    }

    public function exportPdf(): StreamedResponse
    {
        $summary = $this->summary();
        $shortages = $this->shortageSummary();

        return $this->pdfResponse(
            'staff-debts-'.$this->rangeSlug().'.pdf',
            'Staff Debt Report',
            $this->filtersDescription(),
            [
                'Debts in range' => $summary['debts'].' across '.$summary['staff'].' staff',
                'Total charged' => '₦'.number_format($summary['charged'], 2),
                'Total repaid' => '₦'.number_format($summary['repaid'], 2),
                'Still outstanding' => '₦'.number_format($summary['outstanding'], 2).' over '.$summary['pending_count'].' pending debt(s)',
                'Raised during handover' => '₦'.number_format($summary['handover_charged'], 2).' charged, ₦'.number_format($summary['handover_outstanding'], 2).' outstanding',
                'Recorded by a person' => '₦'.number_format($summary['recorded_charged'], 2).' charged, ₦'.number_format($summary['recorded_outstanding'], 2).' outstanding',
                // Stated as its own line, never added to the totals above:
                // nobody has ruled on these yet, so none of it is money that
                // can legitimately be deducted from anyone today.
                'Handover shortages not yet ruled on' => $shortages['count'] === 0
                    ? 'None'
                    : '₦'.number_format($shortages['value'], 2).' over '.$shortages['count'].' line(s), '
                        .$shortages['staff'].' custodian(s) — not counted in the totals above',
            ],
            $this->printHeaders(),
            $this->printRows()
        );
    }

    /**
     * Unruled handover shortages on their own — the queue a manager has to
     * work through before any of it can become a deduction.
     */
    public function exportShortagesCsv(): StreamedResponse
    {
        return $this->csvResponse(
            'unresolved-handover-shortages-'.$this->rangeSlug().'.csv',
            [
                'Business Day', 'Opened At', 'Custodian', 'Warehouse', 'Session',
                'Item', 'Qty Short', 'Unit Price', 'Value', 'Status', 'Age (days)',
            ],
            $this->unresolvedShortages()->map(fn (array $r) => [
                $r['business_day'],
                $r['created_at']?->format('Y-m-d H:i'),
                $r['staff_name'],
                $r['warehouse'] ?? '',
                $r['session_id'] ? '#'.$r['session_id'] : '',
                $r['item_name'],
                number_format($r['quantity'], 2, '.', ''),
                number_format($r['unit_price'], 2, '.', ''),
                number_format($r['value'], 2, '.', ''),
                $r['status_label'],
                $r['age_days'],
            ])
        );
    }

    /** Empty dates mean "every date", and a filename has to say so. */
    private function rangeSlug(): string
    {
        if ($this->dateFrom === '' && $this->dateTo === '') {
            return 'all-dates';
        }

        return ($this->dateFrom ?: 'start').'-to-'.($this->dateTo ?: 'today');
    }

    /**
     * Printed on the PDF so a filtered export can never be mistaken for
     * the whole ledger once it has left the screen.
     */
    public function filtersDescription(): string
    {
        $staff = $this->selectedStaffNames();

        return ($this->dateFrom === '' && $this->dateTo === ''
                ? 'All dates'
                : 'Business days '.($this->dateFrom ?: 'start').' to '.($this->dateTo ?: 'today'))
            .' (trading day closes 9am WAT)'
            .' | Staff: '.($staff === [] ? 'All' : implode(', ', $staff))
            .' | Status: '.match ($this->status) {
                'outstanding' => 'Pending / outstanding only',
                'open' => 'Open only',
                'partially_settled' => 'Partially settled only',
                'settled' => 'Settled only',
                default => 'All',
            }
        .' | Source: '.match ($this->origin) {
            'handover' => 'Raised during handover',
            'recorded' => 'Recorded by a person',
            default => 'All',
        };
    }
}
