<?php

namespace App\Filament\Ceo\Pages;

use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\PayrollPaymentService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

/**
 * The one write carve-out inside the otherwise read-only CEO panel — a
 * per-line "Mark Paid", mirroring Dashboard::computeMissingSnapshots()'s
 * precedent for a narrow single-purpose write here. Everything else
 * (compiling, deductions, sealing, voiding) lives in the admin panel's
 * PayrollRuns/PayrollRunDetail, restricted to manager+super_admin.
 */
class Payroll extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Payroll';

    protected string $view = 'filament-ceo.pages.payroll';

    public ?int $selectedRunId = null;

    /** @var array<int, string> keyed by payroll_line_id */
    public array $paymentMethod = [];

    /** @var array<int, ?string> keyed by payroll_line_id */
    public array $paymentReference = [];

    public function mount(): void
    {
        $this->selectedRunId = $this->runs()->first()?->id;
    }

    public function runs(): \Illuminate\Support\Collection
    {
        return PayrollRun::query()
            ->whereIn('status', ['sealed', 'closed', 'voided'])
            ->withCount('lines')
            ->orderByDesc('period_start')
            ->get();
    }

    #[Computed]
    public function selectedRun(): ?PayrollRun
    {
        return PayrollRun::with(['lines.user', 'lines.deductions.staffDebt', 'preparer'])
            ->find($this->selectedRunId);
    }

    public function selectRun(int $runId): void
    {
        $this->selectedRunId = $runId;
        unset($this->selectedRun);
    }

    public function markPaid(int $lineId): void
    {
        $line = $this->selectedRun?->lines->firstWhere('id', $lineId);

        if (! $line) {
            return;
        }

        $method = $this->paymentMethod[$lineId] ?? 'cash';
        $reference = $this->paymentReference[$lineId] ?? null;

        try {
            (new PayrollPaymentService())->markPaid($line, $method, $reference, null, auth()->user());
            unset($this->selectedRun);
            Notification::make()->title('Marked paid — '.$line->user?->name)->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not mark paid')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
