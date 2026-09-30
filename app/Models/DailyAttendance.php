<?php

namespace App\Models;

use App\Support\VenueTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAttendance extends Model
{
    protected $table = 'daily_attendances';

    // Views don't have true timestamps, disable them
    public $timestamps = false;

    // Treat it as read-only (unguarded doesn't matter since we won't insert)
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'first_punch' => 'datetime',
        'last_punch' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The terminal's own record for this badge — where the name typed into
     * the machine lives. Joined on the machine ID rather than a foreign key
     * because the badge, not the staff profile, is what both sides share;
     * rows with no paired user still resolve.
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(BiometricEnrollment::class, 'biometric_id', 'biometric_id');
    }

    /**
     * When this person was due in, as a Lagos wall-clock instant on this row's
     * own date, or null if nobody has set a shift time for them.
     *
     * Lateness is a wall-clock question, so everything here works in venue
     * time rather than the UTC the columns are stored in.
     */
    public function expectedStartAt(): ?Carbon
    {
        if (! $this->user || blank($this->user->shift_start_time)) {
            return null;
        }

        return Carbon::parse(
            $this->dateString().' '.$this->user->shift_start_time,
            VenueTime::TIMEZONE
        );
    }

    public function firstPunchLocal(): ?Carbon
    {
        return $this->first_punch
            ? Carbon::parse($this->first_punch)->timezone(VenueTime::TIMEZONE)
            : null;
    }

    public function lastPunchLocal(): ?Carbon
    {
        return $this->last_punch
            ? Carbon::parse($this->last_punch)->timezone(VenueTime::TIMEZONE)
            : null;
    }

    /**
     * "Late", "On Time", or "No Shift Time" when there is nothing to judge
     * against — an unpaired badge, or a staff profile with no shift start.
     *
     * Lives on the model because the admin table, the ceo table and the CSV
     * export all have to agree; three copies of this comparison would
     * eventually disagree about who was late.
     */
    public function status(): string
    {
        $expected = $this->expectedStartAt();
        $firstPunch = $this->firstPunchLocal();

        if ($expected === null || $firstPunch === null) {
            return 'No Shift Time';
        }

        return $firstPunch->greaterThan($expected) ? 'Late' : 'On Time';
    }

    /**
     * Minutes past the shift start, or null when there is no shift time to
     * measure against. Negative is never returned — early is simply 0, so a
     * spreadsheet can total the column without early arrivals cancelling out
     * somebody else's lateness.
     */
    public function minutesLate(): ?int
    {
        $expected = $this->expectedStartAt();
        $firstPunch = $this->firstPunchLocal();

        if ($expected === null || $firstPunch === null) {
            return null;
        }

        return max(0, (int) round($expected->diffInMinutes($firstPunch, false)));
    }

    private function dateString(): string
    {
        return $this->date instanceof \DateTimeInterface
            ? Carbon::instance($this->date)->toDateString()
            : Carbon::parse($this->date)->toDateString();
    }
}
