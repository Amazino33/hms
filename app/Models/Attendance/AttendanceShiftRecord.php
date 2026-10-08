<?php

namespace App\Models\Attendance;

use App\Models\AttendanceLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One judged shift.
 *
 * Append-only. Re-evaluation supersedes rather than edits, so the record that
 * justified a fine stays readable even after the schedule, the settings and
 * the punch attribution have all moved on.
 */
class AttendanceShiftRecord extends Model
{
    use HasFactory;

    /**
     * Outcomes, worst last. Order matters: the board colours a combined
     * outcome by its most severe part, and the review queue sorts by it.
     */
    public const OUTCOMES = [
        'present',
        'unlinked',
        'late',
        'early_leave',
        'late_relief',
        'late_and_early_leave',
        'no_clockout',
        'late_no_clockout',
        'absent',
    ];

    protected $fillable = [
        'user_id',
        'assignment_id',
        'template_id',
        'attendance_setting_id',
        'shift_date',
        'is_handover',
        'scheduled_start_at',
        'scheduled_end_at',
        'window_start_at',
        'window_end_at',
        'clock_in_at',
        'clock_out_at',
        'clock_in_log_id',
        'clock_out_log_id',
        'raw_punch_count',
        'collapsed_punch_count',
        'late_minutes',
        'early_leave_minutes',
        'outcome',
        'review_flags',
        'finalised_at',
        'current_key',
    ];

    protected $attributes = [
        'is_handover' => false,
        'raw_punch_count' => 0,
        'collapsed_punch_count' => 0,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
    ];

    protected $casts = [
        'shift_date' => 'date',
        'is_handover' => 'boolean',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'window_start_at' => 'datetime',
        'window_end_at' => 'datetime',
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'finalised_at' => 'datetime',
        'superseded_at' => 'datetime',
        'review_flags' => 'array',
        'raw_punch_count' => 'integer',
        'collapsed_punch_count' => 'integer',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'template_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftAssignment::class, 'assignment_id');
    }

    public function settings(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(AttendanceFine::class, 'shift_record_id');
    }

    public function clockInLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'clock_in_log_id');
    }

    public function clockOutLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'clock_out_log_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function scopeForDate(Builder $query, string $shiftDate): Builder
    {
        return $query->whereDate('shift_date', $shiftDate);
    }

    /**
     * The value the unique index enforces one-current-record on. Null once
     * superseded, which is what lets the replacement take the slot.
     */
    public static function currentKeyFor(int $userId, \DateTimeInterface $scheduledStartAt): string
    {
        return $userId.':'.$scheduledStartAt->format('Y-m-d H:i:s');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->review_flags ?? [], true);
    }

    /**
     * How bad this outcome is, for colouring and sorting. A combined outcome
     * ranks by its worse half, which is why OUTCOMES is ordered.
     */
    public function severity(): int
    {
        $index = array_search($this->outcome, self::OUTCOMES, true);

        return $index === false ? 0 : $index;
    }

    public function outcomeColour(): string
    {
        return match ($this->outcome) {
            'present' => 'success',
            'unlinked' => 'gray',
            'late', 'early_leave' => 'warning',
            'late_relief', 'late_and_early_leave' => 'orange',
            'no_clockout', 'late_no_clockout' => 'danger',
            'absent' => 'danger',
            default => 'gray',
        };
    }

    public function outcomeLabel(): string
    {
        return match ($this->outcome) {
            'present' => 'Present',
            'late' => 'Late',
            'late_relief' => 'Late (relief)',
            'early_leave' => 'Left early',
            'late_and_early_leave' => 'Late + left early',
            'no_clockout' => 'No clock-out',
            'late_no_clockout' => 'Late + no clock-out',
            'absent' => 'Absent',
            'unlinked' => 'Not linked',
            default => $this->outcome,
        };
    }
}
