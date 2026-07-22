<?php

namespace App\Services;

use App\Models\PayrollLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place the payroll module ever touches the StaffDebt ledger.
 * Deductions are only intent-at-seal (payroll_line_deductions.amount) until
 * a line is actually marked paid here — that's the moment a real
 * StaffDebtRepayment gets booked, one per deduction on the line, so a
 * sealed-but-never-paid run never phantom-repays anything.
 */
class PayrollPaymentService
{
    public function markPaid(
        PayrollLine $line,
        string $paymentMethod,
        ?string $paymentReference,
        ?string $receiptPath,
        User $paidBy,
    ): PayrollLine {
        if ($line->run->status !== 'sealed') {
            throw new RuntimeException('Only a line on a sealed run can be marked paid.');
        }

        if (! $line->isPending()) {
            throw new RuntimeException('This line has already been paid, or is no longer payable.');
        }

        if (! in_array($paymentMethod, ['cash', 'transfer'], true)) {
            throw new RuntimeException('Invalid payment method.');
        }

        return DB::transaction(function () use ($line, $paymentMethod, $paymentReference, $receiptPath, $paidBy) {
            foreach ($line->deductions()->whereNull('staff_debt_repayment_id')->get() as $deduction) {
                $debt = $deduction->staffDebt;

                $repayment = $debt->repayments()->create([
                    'amount' => $deduction->amount,
                    'method' => 'salary_deduction',
                    'recorded_by' => $paidBy->id,
                    'notes' => "Deducted via payroll run #{$line->payroll_run_id}, payslip #{$line->id}.",
                ]);

                $debt->refreshStatus();

                $deduction->update(['staff_debt_repayment_id' => $repayment->id]);
            }

            $line->update([
                'status' => 'paid',
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'receipt_path' => $receiptPath,
                'paid_by' => $paidBy->id,
                'paid_at' => now(),
            ]);

            return $line->fresh('deductions');
        });
    }
}
