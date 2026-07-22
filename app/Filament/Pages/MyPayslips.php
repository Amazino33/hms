<?php

namespace App\Filament\Pages;

use App\Services\PayrollAcknowledgementService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class MyPayslips extends \Filament\Pages\Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'My Payslips';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.my-payslips';

    /** @var array<int, string> keyed by payroll_line_id */
    public array $disputeReason = [];

    /** @var array<int, ?string> keyed by payroll_line_id */
    public array $disputeReportedAmount = [];

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function acknowledge(int $lineId): void
    {
        $line = auth()->user()->payrollLines()->find($lineId);

        if (! $line) {
            return;
        }

        try {
            (new PayrollAcknowledgementService())->acknowledge($line, auth()->user());
            Notification::make()->title('Thanks — payslip acknowledged')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not acknowledge')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function dispute(int $lineId): void
    {
        $line = auth()->user()->payrollLines()->find($lineId);

        if (! $line) {
            return;
        }

        $reason = $this->disputeReason[$lineId] ?? '';
        $reported = $this->disputeReportedAmount[$lineId] ?? null;

        try {
            (new PayrollAcknowledgementService())->dispute(
                $line,
                auth()->user(),
                $reason,
                $reported !== null && $reported !== '' ? (float) $reported : null,
            );

            Notification::make()->title('Dispute recorded — a manager will follow up')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not record dispute')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    protected function getViewData(): array
    {
        $lines = Auth::user()->payrollLines()
            ->with(['run', 'deductions.staffDebt'])
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['sealed', 'closed']))
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->get();

        return [
            'lines' => $lines,
        ];
    }
}
