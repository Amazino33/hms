<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Which specific debt a line's deduction is applied to. Created at seal
 * time as intent only (amount earmarked); staff_debt_repayment_id stays
 * null until PayrollPaymentService::markPaid() actually books the
 * StaffDebtRepayment — the debt ledger is only ever touched on confirmed
 * payment, never at draft or seal.
 */
class PayrollLineDeduction extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('payroll_line_deduction')
            ->dontLogEmptyChanges();
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    public function staffDebt(): BelongsTo
    {
        return $this->belongsTo(StaffDebt::class);
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(StaffDebtRepayment::class, 'staff_debt_repayment_id');
    }
}
