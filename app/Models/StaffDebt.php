<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class StaffDebt extends Model
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
            ->logOnlyDirty()
            ->useLogName('staff_debt')
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(StaffDebtRepayment::class);
    }

    public function totalRepaid(): float
    {
        return (float) $this->repayments()->sum('amount');
    }

    public function remainingBalance(): float
    {
        return max(0, (float) $this->amount - $this->totalRepaid());
    }

    /**
     * Recompute and persist status from the current repayment total.
     * Call this after any repayment is recorded.
     */
    public function refreshStatus(): void
    {
        $remaining = $this->remainingBalance();

        $status = match (true) {
            $remaining <= 0 => 'settled',
            $this->totalRepaid() > 0 => 'partially_settled',
            default => 'open',
        };

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }
}
