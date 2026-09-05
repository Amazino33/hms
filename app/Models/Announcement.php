<?php

namespace App\Models;

use App\Services\AnnouncementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Announcement extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'must_acknowledge' => 'boolean',
        'show_on_kiosk' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'unpublished_at' => 'datetime',
    ];

    /**
     * Any write here can change what is live — publishing, withdrawing,
     * editing an expiry — so the shared live-list cache is dropped on
     * every save. Without this a manager would publish an urgent notice
     * and watch nothing appear on anyone's screen for up to a minute.
     */
    protected static function booted(): void
    {
        static::saved(fn () => AnnouncementService::flushLiveCache());
        static::deleted(fn () => AnnouncementService::flushLiveCache());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('announcement')
            ->dontLogEmptyChanges();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AnnouncementTarget::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(AnnouncementAcknowledgement::class);
    }

    /**
     * Published, not withdrawn, not expired, and its start time has
     * arrived. Every "is there anything for me to read?" query goes
     * through here so the three timestamp columns can never be
     * interpreted three slightly different ways in three places.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->whereNull('unpublished_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    public function status(): string
    {
        if ($this->published_at === null) {
            return 'draft';
        }

        if ($this->unpublished_at !== null) {
            return 'unpublished';
        }

        if ($this->published_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'published';
    }

    public function isLive(): bool
    {
        return $this->status() === 'published';
    }

    /**
     * Replace this announcement's role targets with exactly $roles.
     *
     * The mass delete below bypasses model events, so the shared live
     * cache is dropped explicitly here — AnnouncementTarget's own booted()
     * hook never fires for it.
     *
     * @param  array<int, string>  $roles
     */
    public function syncTargetRoles(array $roles): void
    {
        $roles = array_values(array_unique(array_filter($roles)));

        // An empty list produces "NOT IN ()", which Laravel renders as an
        // always-true clause — every target is removed, which is exactly
        // what clearing the roles should do.
        $this->targets()->whereNotIn('role_name', $roles)->delete();

        foreach ($roles as $role) {
            $this->targets()->firstOrCreate(['role_name' => $role]);
        }

        $this->load('targets');

        AnnouncementService::flushLiveCache();
    }

    /**
     * Roster size and signature count — the two numbers a manager
     * actually looks at. Uses loaded counts when the caller has
     * eager-loaded them, so a table listing does not fire 2 queries per
     * row.
     */
    public function recipientCount(): int
    {
        return $this->recipients_count ?? $this->recipients()->count();
    }

    public function acknowledgedCount(): int
    {
        return $this->acknowledgements_count ?? $this->acknowledgements()->count();
    }

    public function outstandingCount(): int
    {
        return max(0, $this->recipientCount() - $this->acknowledgedCount());
    }
}
