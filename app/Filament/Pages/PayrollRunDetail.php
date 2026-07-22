<?php

namespace App\Filament\Pages;

use App\Models\PayrollLine;
use App\Models\PayrollLineDeduction;
use App\Models\PayrollRun;
use App\Models\StaffDebt;
use App\Services\PayrollCompilationService;
use App\Services\PayrollVoidService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class PayrollRunDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.payroll-run-detail';

    protected static ?string $slug = 'payroll-run-detail';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $payrollRunId = null;

    /** @var array<int, ?int> keyed by payroll_line_id */
    public array $deductionDebtId = [];

    /** @var array<int, ?string> keyed by payroll_line_id */
    public array $deductionAmount = [];

    public string $voidReason = '';

    public function mount(?int $run_id = null): void
    {
        $queryValue = request()->integer('run_id');
        $this->payrollRunId = $run_id ?? ($queryValue > 0 ? $queryValue : null);

        if (! $this->run) {
            redirect('/admin/payroll-runs');
        }
    }

    #[Computed]
    public function run(): ?PayrollRun
    {
        return PayrollRun::with([
            'preparer', 'voider', 'supersedes', 'supersededBy',
            'lines.user', 'lines.deductions.staffDebt',
        ])->find($this->payrollRunId);
    }

    public function getTitle(): string
    {
        $run = $this->run;

        return $run ? 'Payroll Run — '.$run->period_start->format('M j').' – '.$run->period_end->format('M j, Y') : 'Payroll Run';
    }

    protected function refreshRun(): void
    {
        unset($this->run);
    }

    /**
     * Every open/partially-settled debt for a line's user — the pool a
     * manager can pick from to earmark a deduction. Excludes debts already
     * fully allocated on THIS line (still shown, since editing amount is
     * allowed) — no filtering needed beyond status, setDeduction() itself
     * enforces the amount bound.
     */
    public function openDebtsFor(int $userId): \Illuminate\Support\Collection
    {
        return StaffDebt::where('user_id', $userId)
            ->whereIn('status', ['open', 'partially_settled'])
            ->get();
    }

    public function refreshDraft(): void
    {
        try {
            (new PayrollCompilationService())->refreshDraft($this->run);
            $this->refreshRun();
            Notification::make()->title('Figures recomputed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not recompute')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function addDeduction(int $lineId): void
    {
        $line = $this->run->lines->firstWhere('id', $lineId);

        if (! $line) {
            return;
        }

        $debtId = $this->deductionDebtId[$lineId] ?? null;
        $amount = (float) ($this->deductionAmount[$lineId] ?? 0);

        if (! $debtId || $amount <= 0) {
            Notification::make()->title('Pick a debt and enter an amount greater than zero')->warning()->send();

            return;
        }

        $debt = StaffDebt::find($debtId);

        if (! $debt) {
            return;
        }

        try {
            (new PayrollCompilationService())->setDeduction($line, $debt, $amount);
            $this->deductionDebtId[$lineId] = null;
            $this->deductionAmount[$lineId] = null;
            $this->refreshRun();
            Notification::make()->title('Deduction set')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not set deduction')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function removeDeduction(int $deductionId): void
    {
        $deduction = PayrollLineDeduction::find($deductionId);

        if (! $deduction) {
            return;
        }

        try {
            (new PayrollCompilationService())->removeDeduction($deduction);
            $this->refreshRun();
            Notification::make()->title('Deduction removed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not remove deduction')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function sealRun(): void
    {
        try {
            (new PayrollCompilationService())->sealRun($this->run);
            $this->refreshRun();
            Notification::make()->title('Payroll run sealed — figures are now frozen and visible to the CEO for payment')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not seal run')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function voidAndReissue(): void
    {
        if (trim($this->voidReason) === '') {
            Notification::make()->title('A reason is required to void this run')->warning()->send();

            return;
        }

        try {
            $newRun = app(PayrollVoidService::class)->voidAndReissue($this->run, $this->voidReason, auth()->user());
            Notification::make()->title('Run voided — a fresh draft has been created for the same period')->success()->send();
            $this->redirect("/admin/payroll-run-detail?run_id={$newRun->id}");
        } catch (\Exception $e) {
            Notification::make()->title('Could not void this run')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function canEditDeductions(): bool
    {
        return $this->run?->status === 'draft';
    }

    public function canVoid(): bool
    {
        return in_array($this->run?->status, ['sealed', 'closed'], true);
    }
}
