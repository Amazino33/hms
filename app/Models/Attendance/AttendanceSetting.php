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
        'window_before_minutes',
        'window_after_minutes',
        'finalise_delay_minutes',
        'max_device_wait_minutes',
        'early_leave_grace_minutes',
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
        'window_before_minutes' => 120,
        'window_after_minutes' => 240,
        'finalise_delay_minutes' => 60,
        'max_device_wait_minutes' => 1440,
        'early_leave_grace_minutes' => 0,
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
        'window_before_minutes' => 'integer',
        'window_after_minutes' => 'integer',
        'finalise_delay_minutes' => 'integer',
        'max_device_wait_minutes' => 'integer',
        'early_leave_grace_minutes' => 'integer',
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

    /**
     * Whether a fine for a shift on this date is real money or a dry run.
     *
     * Four independent conditions, ALL of which must hold. Written so that
     * every failure — including a missing settings row, a null column, or a
     * config nobody has set — lands on shadow. The asymmetry is deliberate:
     * a shadow fine that should have been live costs nothing and can be
     * re-evaluated, while a live fine that should have been shadow takes
     * money off somebody who was told this was a trial.
     *
     * Note the strict `=== false` on shadow_mode. A null there means "nobody
     * has said", and a loose check would read that as "not shadow" and start
     * charging people on the strength of an unset column.
     */
    public static function isLiveOn(CarbonInterface $shiftDate): bool
    {
        if (config('attendance.allow_live_fines') !== true) {
            return false;
        }

        $settings = static::forDate($shiftDate);

        if ($settings === null) {
            return false;
        }

        if ($settings->shadow_mode !== false) {
            return false;
        }

        if ($settings->rules_start_date === null) {
            return false;
        }

        $date = \Carbon\CarbonImmutable::parse(
            $shiftDate->format('Y-m-d'),
            \App\Support\VenueTime::TIMEZONE,
        )->startOfDay();

        $start = \Carbon\CarbonImmutable::parse(
            $settings->rules_start_date->format('Y-m-d'),
            \App\Support\VenueTime::TIMEZONE,
        )->startOfDay();

        return $date->greaterThanOrEqualTo($start);
    }

    /**
     * Why fines are still shadow, in a sentence, for the settings banner.
     * Null when they are live.
     */
    public static function shadowReason(?CarbonInterface $on = null): ?string
    {
        $date = $on ?? now()->timezone(\App\Support\VenueTime::TIMEZONE);

        if (static::isLiveOn($date)) {
            return null;
        }

        if (config('attendance.allow_live_fines') !== true) {
            return 'Live fines are disabled until leave/waiver handling is built. All fines are shadow.';
        }

        $settings = static::forDate($date);

        return match (true) {
            $settings === null => 'No attendance rules have been saved yet, so nothing can be charged.',
            $settings->shadow_mode !== false => 'Shadow mode is on — everything is worked out and nothing is charged.',
            $settings->rules_start_date === null => 'No start date has been announced, so nothing is charged yet.',
            default => 'The announced start date has not been reached yet.',
        };
    }
}
