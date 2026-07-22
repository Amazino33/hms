<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\PayrollLine;
use App\Models\PayrollLineDeduction;
use App\Models\PayrollRun;
use App\Models\StaffDebt;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compiles and edits a payroll run while it is still a draft. base/commission
 * are recomputed every time (a draft is "still compiling"); deduction_amount
 * is only ever changed through setDeduction()/removeDeduction() so it always
 * stays inside the two structural bounds: never more than the debt's own
 * outstanding balance, and never enough to push net pay below the configured
 * floor. Nothing here touches the StaffDebt ledger — that only happens at
 * actual payment, in PayrollPaymentService.
 */
class PayrollCompilationService
{
    /**
     * Roles a payroll line is generated for. Deliberately excludes
     * super_admin/ceo (they carry/oversee payroll, they don't receive a
     * line from it) per the locked module decision.
     */
    private const PAYROLL_ROLES = [
        'admin', 'chef', 'manager', 'waiter', 'bartender',
        'storekeeper', 'receptionist', 'porter', 'cashier',
    ];

    public function eligibleStaff(): Collection
    {
        return User::query()
            ->whereNull('left_at')
            ->role(self::PAYROLL_ROLES)
            ->orderBy('name')
            ->get();
    }

    public function draftRun(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?CarbonImmutable $payday,
        User $preparedBy,
        ?PayrollRun $supersedes = null,
    ): PayrollRun {
        return DB::transaction(function () use ($periodStart, $periodEnd, $payday, $preparedBy, $supersedes) {
            $run = PayrollRun::create([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'payday' => $payday?->toDateString(),
                'status' => 'draft',
                'prepared_by' => $preparedBy->id,
                'supersedes_id' => $supersedes?->id,
            ]);

            foreach ($this->eligibleStaff() as $user) {
                $this->compileLineForUser($run, $user, $periodStart, $periodEnd);
            }

            return $run;
        });
    }

    /**
     * Recomputes base/commission/gross/net for every line on a draft run
     * against the latest data — call before display and always right
     * before sealing. deduction_amount is untouched (it's edited only
     * through setDeduction()/removeDeduction()).
     */
    public function refreshDraft(PayrollRun $run): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Only a draft run can be recompiled.');
        }

        $periodStart = CarbonImmutable::parse($run->period_start);
        $periodEnd = CarbonImmutable::parse($run->period_end);

        foreach ($this->eligibleStaff() as $user) {
            $this->compileLineForUser($run, $user, $periodStart, $periodEnd);
        }

        return $run->fresh('lines');
    }

    protected function compileLineForUser(
        PayrollRun $run,
        User $user,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): PayrollLine {
        $base = (float) (StaffSalary::effectiveFor($user, $periodEnd)?->amount ?? 0);
        $commission = $this->commissionFor($user, $periodStart, $periodEnd);
        $gross = round($base + $commission, 2);

        $line = PayrollLine::firstOrNew([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
        ]);

        $line->base_amount = $base;
        $line->commission_amount = $commission;
        $line->gross_amount = $gross;
        $line->net_amount = round($gross - (float) ($line->deduction_amount ?? 0), 2);
        $line->save();

        return $line;
    }

    /**
     * Excludes commission tied to an order that has since been returned or
     * cancelled — the order's CURRENT status, not the status it had when
     * the commission was written (an order can transition to paid, earn a
     * commission, then later be returned).
     */
    protected function commissionFor(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): float
    {
        return (float) Commission::where('user_id', $user->id)
            ->whereBetween('created_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['returned', 'cancelled']))
            ->sum('amount');
    }

    public function setDeduction(PayrollLine $line, StaffDebt $debt, float $amount): PayrollLineDeduction
    {
        $run = $line->run;

        if (! $run->isDraft()) {
            throw new RuntimeException('Deductions can only be set while the run is in draft.');
        }

        if ($debt->user_id !== $line->user_id) {
            throw new RuntimeException('This debt does not belong to this staff member.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Deduction amount must be greater than zero.');
        }

        $outstanding = $debt->remainingBalance();

        if ($amount > $outstanding) {
            throw new RuntimeException(
                "Deduction of ₦{$amount} exceeds the outstanding debt of ₦{$outstanding}."
            );
        }

        $existing = $line->deductions()->where('staff_debt_id', $debt->id)->first();
        $existingAmount = (float) ($existing?->amount ?? 0);
        $otherDeductions = (float) $line->deduction_amount - $existingAmount;
        $newTotalDeduction = round($otherDeductions + $amount, 2);

        $minimumNet = (float) SettingsService::get('payroll_minimum_net', '20000');
        $projectedNet = round((float) $line->gross_amount - $newTotalDeduction, 2);

        if ($projectedNet < $minimumNet) {
            throw new RuntimeException(
                "This deduction would drop net pay to ₦{$projectedNet}, below the minimum net floor of ₦{$minimumNet}."
            );
        }

        return DB::transaction(function () use ($line, $debt, $amount, $existing, $newTotalDeduction) {
            if ($existing) {
                $existing->update(['amount' => $amount]);
                $deduction = $existing;
            } else {
                $deduction = PayrollLineDeduction::create([
                    'payroll_line_id' => $line->id,
                    'staff_debt_id' => $debt->id,
                    'amount' => $amount,
                ]);
            }

            $line->update([
                'deduction_amount' => $newTotalDeduction,
                'net_amount' => round((float) $line->gross_amount - $newTotalDeduction, 2),
            ]);

            return $deduction;
        });
    }

    public function removeDeduction(PayrollLineDeduction $deduction): void
    {
        $line = $deduction->line;
        $run = $line->run;

        if (! $run->isDraft()) {
            throw new RuntimeException('Deductions can only be changed while the run is in draft.');
        }

        DB::transaction(function () use ($deduction, $line) {
            $amount = (float) $deduction->amount;
            $deduction->delete();

            $line->update([
                'deduction_amount' => round((float) $line->deduction_amount - $amount, 2),
                'net_amount' => round((float) $line->net_amount + $amount, 2),
            ]);
        });
    }

    public function sealRun(PayrollRun $run): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Only a draft run can be sealed.');
        }

        if ($run->lines()->count() === 0) {
            throw new RuntimeException('Cannot seal an empty payroll run.');
        }

        return DB::transaction(function () use ($run) {
            $this->refreshDraft($run);

            $run->update([
                'status' => 'sealed',
                'sealed_at' => now(),
            ]);

            return $run->fresh('lines');
        });
    }
}
