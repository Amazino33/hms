<?php

namespace App\Models\Attendance;

use App\Models\AttendanceLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a person should look at, carrying no charge.
 *
 * Marking one reviewed records who and when and does nothing else in Phase 2 —
 * it is an acknowledgement, not a correction. Acting on it means fixing the
 * rota or the link, which has its own screen.
 */
class AttendanceReviewItem extends Model
{
    use HasFactory;

    public const UNSCHEDULED_PUNCH = 'unscheduled_punch';

    public const SUSPECTED_OUTAGE = 'suspected_outage';

    protected $fillable = [
        'user_id',
        'attendance_log_id',
        'shift_record_id',
        'shift_date',
        'reason',
        'detail',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'attendance_log_id');
    }

    public function shiftRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftRecord::class, 'shift_record_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function markReviewed(User $actor): self
    {
        if ($this->resolved_at !== null) {
            return $this;
        }

        $this->forceFill(['resolved_at' => now(), 'resolved_by' => $actor->id])->save();

        return $this;
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            self::UNSCHEDULED_PUNCH => 'Punched when not scheduled',
            self::SUSPECTED_OUTAGE => 'Most staff absent — suspected device outage',
            default => str_replace('_', ' ', ucfirst($this->reason)),
        };
    }
}
