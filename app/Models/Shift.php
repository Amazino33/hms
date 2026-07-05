<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Shift extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('shift')
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'user_id',
        'type',
        'opening_count_session_id',
        'started_at',
        'ended_at',
        'status',
        'declared_cash',
        'declared_pos',
        'supervisor_confirmed_cash',
        'supervisor_confirmed_pos',
        'expected_cash',
        'expected_pos',
        'cash_variance',
        'surplus_amount',
        'settlement_notes',
        'settled_at',
    ];

    /** A shift open longer than this is presumed abandoned/forgotten, not a
     *  genuinely still-active custodian — it can no longer satisfy an
     *  "active shift" guard or be treated as the current shift. */
    public const STALE_AFTER_HOURS = 20;

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'declared_cash' => 'decimal:2',
        'declared_pos' => 'decimal:2',
        'supervisor_confirmed_cash' => 'decimal:2',
        'supervisor_confirmed_pos' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'expected_pos' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'surplus_amount' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(StaffDebt::class);
    }

    public function openingCountSession(): BelongsTo
    {
        return $this->belongsTo(CountSession::class, 'opening_count_session_id');
    }

    // Check if shift is currently active
    public function isActive(): bool
    {
        return is_null($this->ended_at);
    }

    /**
     * A shift left running past STALE_AFTER_HOURS is presumed abandoned
     * (app crash, forgotten sign-out, etc.) rather than a real still-active
     * custodian — it must not satisfy an "active shift" guard.
     */
    public function isStale(): bool
    {
        return $this->isActive() && $this->started_at->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    // Get shift duration in minutes
    public function getDurationAttribute(): ?int
    {
        if (!$this->ended_at) return null;
        
        return $this->started_at->diffInMinutes($this->ended_at);
    }

    // Scope for active shifts
    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    // Scope for completed shifts
    public function scopeCompleted($query)
    {
        return $query->whereNotNull('ended_at');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Active AND not stale — the only shifts that should ever satisfy an
     * "is someone on duty" guard.
     */
    public function scopeActiveNonStale($query, ?string $type = null)
    {
        $query->whereNull('ended_at')
            ->where('started_at', '>=', now()->subHours(self::STALE_AFTER_HOURS));

        return $type ? $query->where('type', $type) : $query;
    }
}
