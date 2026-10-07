<?php

namespace App\Models\Attendance;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One person on one shift pattern, for one stretch of time.
 *
 * Append-only. A schedule change closes this row and opens another; nothing
 * is ever edited except the closing fields, and nothing is ever deleted. The
 * history is what makes a fine arguable months later.
 */
class AttendanceShiftAssignment extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'attendance_shift_template_id',
        'effective_from',
        'effective_to',
        'weekly_days_override',
        'rotation_anchor_date',
        'reason',
        'created_by',
        'ended_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'rotation_anchor_date' => 'date',
        'weekly_days_override' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('attendance_shift_assignment')
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'attendance_shift_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Still running — no end date set. Not the same as "covers today": an
     * assignment starting next month is open but not yet in force.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    /**
     * Assignments in force on a given date. Half-open at neither end: these
     * are whole dates, and effective_to is the last day the assignment
     * applies, inclusive.
     */
    public function scopeCovering(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->format('Y-m-d');

        return $query->whereDate('effective_from', '<=', $day)
            ->where(function (Builder $q) use ($day) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day);
            });
    }

    /**
     * Assignments overlapping a range, which is what both the resolver and
     * the overlap check need. Two ranges overlap unless one ends before the
     * other begins.
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        $fromDay = $from->format('Y-m-d');

        $query->where(function (Builder $q) use ($fromDay) {
            $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $fromDay);
        });

        if ($to !== null) {
            $query->whereDate('effective_from', '<=', $to->format('Y-m-d'));
        }

        return $query;
    }

    public function daysFor(): array
    {
        return $this->weekly_days_override ?? $this->template->weekly_days ?? [];
    }
}
