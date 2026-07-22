<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which specific debt(s) a line's deduction is applied to. Created at
     * seal time as intent only (amount earmarked); staff_debt_repayment_id
     * stays null until PayrollPaymentService::markPaid() actually books the
     * StaffDebtRepayment — the debt ledger is only ever touched on
     * confirmed payment, never at draft or seal, so a voided draft/sealed-
     * but-unpaid run never books a phantom repayment.
     */
    public function up(): void
    {
        Schema::create('payroll_line_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_debt_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->foreignId('staff_debt_repayment_id')->nullable()->constrained('staff_debt_repayments')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_line_deductions');
    }
};
