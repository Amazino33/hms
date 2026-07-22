<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A sealed run is never edited in place — a correction voids it and drafts
 * a fresh run for the same period with supersedes_id pointing back at the
 * voided one, mirroring DailyBusinessSnapshot exactly. Any StaffDebtRepayment
 * already booked by PayrollPaymentService::markPaid() on the voided run's
 * paid lines is left untouched (real money already moved) — the reissued
 * draft's fresh lines start with deduction_amount = 0, and
 * PayrollCompilationService::setDeduction() bounds every new deduction
 * against StaffDebt::remainingBalance(), which already nets out those prior
 * repayments, so re-adding a deduction for the same debt can never
 * double-count it.
 */
class PayrollVoidService
{
    public function __construct(private PayrollCompilationService $compiler)
    {
    }

    public function voidAndReissue(PayrollRun $run, string $reason, User $voidedBy): PayrollRun
    {
        if (! in_array($run->status, ['sealed', 'closed'], true)) {
            throw new RuntimeException('Only a sealed or closed run can be voided and reissued.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to void a payroll run.');
        }

        return DB::transaction(function () use ($run, $reason, $voidedBy) {
            $run->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => $voidedBy->id,
                'voided_at' => now(),
            ]);

            return $this->compiler->draftRun(
                CarbonImmutable::parse($run->period_start),
                CarbonImmutable::parse($run->period_end),
                $run->payday ? CarbonImmutable::parse($run->payday) : null,
                $voidedBy,
                $run,
            );
        });
    }
}
