<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * One charge arising from one judged shift.
 *
 * Append-only. Every figure is a snapshot taken at creation — the amount, the
 * settings version, and whether it was shadow — because the question a person
 * asks months later is "why was I charged that", and the honest answer has to
 * be the rules as they stood then.
 */
class AttendanceFine extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_record_id',
        'user_id',
        'shift_date',
        'kind',
        'type',
        'amount',
        'attendance_setting_id',
        'is_shadow',
    ];

    protected $attributes = [
        'kind' => 'fine',
        // The safe default, consistent with isLiveOn()'s fail-closed design:
        // a row created without anybody deciding is a dry run, not a charge.
        'is_shadow' => true,
    ];

    protected $casts = [
        'shift_date' => 'date',
        'amount' => 'integer',
        'is_shadow' => 'boolean',
        'voided_at' => 'datetime',
    ];

    public function shiftRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftRecord::class, 'shift_record_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function settings(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_shadow', false);
    }

    public function scopeShadow(Builder $query): Builder
    {
        return $query->where('is_shadow', true);
    }

    public function scopeFines(Builder $query): Builder
    {
        return $query->where('kind', 'fine');
    }

    public function scopePayDeductions(Builder $query): Builder
    {
        return $query->where('kind', 'pay_deduction');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isPaid(): bool
    {
        return $this->payroll_run_id !== null;
    }

    /**
     * Withdraw a charge.
     *
     * A null actor means the system did it — a re-evaluation, not a person's
     * decision. Anything already carried into a payroll run is refused
     * outright: voiding it here would silently disagree with a payslip
     * somebody has already been handed. Guarded now, before Phase 3 can set
     * that column, so the rule exists before the hazard does.
     *
     * @throws ValidationException
     */
    public function void(string $reason, ?User $actor = null): self
    {
        if ($this->isPaid()) {
            throw ValidationException::withMessages([
                'void_reason' => 'This charge is already part of payroll run #'.$this->payroll_run_id
                    .' and cannot be voided here — it has to be corrected through payroll.',
            ]);
        }

        if ($this->isVoided()) {
            return $this;
        }

        $this->forceFill([
            'voided_at' => now(),
            'voided_by' => $actor?->id,
            'void_reason' => $reason,
        ])->save();

        return $this;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'late' => 'Late',
            'late_relief' => 'Late (relief shift)',
            'early_leave' => 'Left early',
            'no_clockout' => 'No clock-out',
            'absent' => 'Absent',
            'absence_day_pay' => 'Day pay withheld',
            default => $this->type,
        };
    }
}
