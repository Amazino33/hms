<?php

namespace App\Models\Attendance;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One dated version of the attendance rules.
 *
 * Saving the settings form writes a new row; old rows are never touched. A
 * fine raised under March's figures stays explainable in June, which is the
 * difference between a penalty somebody can check and one they can only be
 * told about.
 *
 * Nothing in Phase 1 reads these except the settings page and its tests.
 */
class AttendanceSetting extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'effective_from',
        'grace_minutes',
        'duplicate_punch_window_minutes',
        'fine_late',
        'fine_late_relief',
        'fine_early_leave',
        'fine_no_clockout',
        'fine_absent',
        'absence_day_pay_amount',
        'rules_start_date',
        'shadow_mode',
        'created_by',
    ];

    /**
     * Mirrors the database defaults so a freshly created version reads the
     * same before and after a refresh.
     *
     * shadow_mode is the one that matters: a model instance created without
     * the key carries NULL, which is falsy, so Phase 2 reading it straight
     * back would conclude the rules are live and start charging people. The
     * safe state has to be the one you get by default, not the one you get
     * if you remember to reload.
     */
    protected $attributes = [
        'shadow_mode' => true,
        'grace_minutes' => 15,
        'duplicate_punch_window_minutes' => 30,
        'fine_late' => 500,
        'fine_late_relief' => 1000,
        'fine_early_leave' => 1500,
        'fine_no_clockout' => 1500,
        'fine_absent' => 3000,
    ];

    protected $casts = [
        'effective_from' => 'date',
        'rules_start_date' => 'date',
        'shadow_mode' => 'boolean',
        'grace_minutes' => 'integer',
        'duplicate_punch_window_minutes' => 'integer',
        'fine_late' => 'integer',
        'fine_late_relief' => 'integer',
        'fine_early_leave' => 'integer',
        'fine_no_clockout' => 'integer',
        'fine_absent' => 'integer',
        'absence_day_pay_amount' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->useLogName('attendance_setting')
            ->dontLogEmptyChanges();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The version in force on a given date: the latest one that had already
     * taken effect by then. Null when the date predates every version, which
     * callers must handle rather than fall back to defaults — there is no
     * honest default for "what were the rules before any were set?".
     */
    public static function forDate(CarbonInterface $date): ?self
    {
        return static::query()
            ->whereDate('effective_from', '<=', $date->format('Y-m-d'))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public static function current(): ?self
    {
        return static::forDate(now()->timezone(\App\Support\VenueTime::TIMEZONE));
    }
}
