<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A badge on the fingerprint terminal.
 *
 * device_name is what somebody typed on the keypad — often shortened,
 * sometimes misspelled, occasionally shared. It is a hint for a human
 * choosing who to link, never a key: linking is by device_user_id only.
 */
class AttendanceDeviceUser extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'device_user_id',
        'device_name',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('attendance_device_user')
            ->dontLogEmptyChanges();
    }

    public function links(): HasMany
    {
        return $this->hasMany(AttendanceDeviceLink::class);
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * The one link that counts: not voided, not ended. At most one can exist
     * at a time — DeviceLinkService enforces that.
     */
    public function activeLink(): ?AttendanceDeviceLink
    {
        return $this->links()->active()->latest('id')->first();
    }

    public function linkedUser(): ?User
    {
        return $this->activeLink()?->user;
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('retired_at')
            ->whereDoesntHave('links', fn (Builder $q) => $q->active());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * How close this badge's device name is to a staff member's name, 0-100.
     *
     * A hint for the admin's eyes only — nothing auto-links on it. A device
     * holding "VICTOR/network" and a staff list holding two Victors is
     * exactly the case where a confident-looking match is wrong.
     */
    public function suggestedMatch(): ?array
    {
        if (blank($this->device_name)) {
            return null;
        }

        $best = null;

        foreach (User::whereNull('left_at')->get(['id', 'name']) as $user) {
            similar_text(
                mb_strtolower($this->device_name),
                mb_strtolower($user->name),
                $percent,
            );

            if ($best === null || $percent > $best['score']) {
                $best = ['user' => $user, 'score' => $percent];
            }
        }

        // Below roughly half the characters in common it is noise, and a bad
        // suggestion is worse than none: it invites a careless click.
        return ($best && $best['score'] >= 50) ? $best : null;
    }
}
