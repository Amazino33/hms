<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One badge belonging to one person, for one stretch of time.
 *
 * Append-only. Nothing is edited but the closing fields, and nothing is
 * deleted — which is the only reason a punch from six months ago can still be
 * traced to the person who made it.
 */
class AttendanceDeviceLink extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'attendance_device_user_id',
        'user_id',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'voided_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('attendance_device_link')
            ->dontLogEmptyChanges();
    }

    public function deviceUser(): BelongsTo
    {
        return $this->belongsTo(AttendanceDeviceUser::class, 'attendance_device_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Live right now: never voided, never ended.
     *
     * A voided link is excluded everywhere, including from history lookups
     * that ask "who held this badge" — the answer is nobody, because the link
     * is an assertion that was withdrawn.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->whereNull('effective_to');
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isActive(): bool
    {
        return $this->voided_at === null && $this->effective_to === null;
    }
}
