<?php

namespace App\Models\Attendance;

use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A shift pattern staff can be assigned to.
 *
 * Deliberately NOT related to App\Models\Shift, which is a different concept
 * entirely — a POS/accounting work session with floats and settlements. This
 * is a schedule: what someone is *expected* to work, which is the only thing
 * attendance may ever be judged against.
 */
class AttendanceShiftTemplate extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'start_time',
        'duration_minutes',
        'pattern_type',
        'weekly_days',
        'rotation_on_days',
        'rotation_off_days',
        'is_handover',
        'created_by',
    ];

    /**
     * The database default is false, but a model created without the key
     * carries a NULL in memory until it is refreshed — and anything reading
     * that instance (duplicate-and-replace, most obviously) then writes the
     * NULL straight back into a NOT NULL column.
     */
    protected $attributes = [
        'is_handover' => false,
    ];

    protected $casts = [
        'weekly_days' => 'array',
        'is_handover' => 'boolean',
        'retired_at' => 'datetime',
        'duration_minutes' => 'integer',
        'rotation_on_days' => 'integer',
        'rotation_off_days' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('attendance_shift_template')
            ->dontLogEmptyChanges();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AttendanceShiftAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function isWeekly(): bool
    {
        return $this->pattern_type === 'weekly';
    }

    public function isRotation(): bool
    {
        return $this->pattern_type === 'rotation';
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * A template becomes read-only the moment anyone is scheduled on it.
     * Editing 08:00 to 09:00 in place would retroactively change who was late
     * on every day already worked under it; "Duplicate and replace" exists so
     * that change is dated instead.
     */
    public function isLocked(): bool
    {
        return $this->assignments()->exists();
    }

    public function fullCycleDays(): int
    {
        return (int) $this->rotation_on_days + (int) $this->rotation_off_days;
    }

    /**
     * The shift start as a Lagos instant on the given date.
     *
     * The shift date is always the Lagos date of the *scheduled start*, never
     * of the end — an overnight shift belongs to the day it began, and
     * deliberately does not use BusinessDay's 9am trading boundary, which is
     * a reporting convention for money rather than for rostering people.
     */
    public function startsOn(CarbonInterface $date): \Carbon\Carbon
    {
        return \Carbon\Carbon::parse($date->format('Y-m-d'), VenueTime::TIMEZONE)
            ->setTimeFromTimeString($this->startTimeString());
    }

    public function endsOn(CarbonInterface $date): \Carbon\Carbon
    {
        return $this->startsOn($date)->addMinutes($this->duration_minutes);
    }

    /**
     * How many calendar days past the start date the shift ends — 0 when it
     * ends the same day, 1 when it spills into tomorrow. Drives the "+1 day"
     * marker the admin needs to read a 24h or overnight pattern correctly.
     */
    public function endDayOffset(): int
    {
        $reference = \Carbon\Carbon::parse('2000-01-03', VenueTime::TIMEZONE);

        return $this->startsOn($reference)
            ->startOfDay()
            ->diffInDays($this->endsOn($reference)->startOfDay());
    }

    public function startTimeString(): string
    {
        return $this->start_time instanceof \DateTimeInterface
            ? $this->start_time->format('H:i:s')
            : (string) $this->start_time;
    }

    public function durationHours(): float
    {
        return round($this->duration_minutes / 60, 2);
    }

    /**
     * "08:00 - 18:00" or "08:00 - 08:00 (+1 day)".
     */
    public function describeHours(): string
    {
        $reference = \Carbon\Carbon::parse('2000-01-03', VenueTime::TIMEZONE);
        $offset = $this->endDayOffset();

        return $this->startsOn($reference)->format('H:i')
            .' - '.$this->endsOn($reference)->format('H:i')
            .($offset > 0 ? ' (+'.$offset.' day'.($offset > 1 ? 's' : '').')' : '');
    }
}
