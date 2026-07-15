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
        'starting_float',
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
        'cashier_counted_cash',
        'cash_confirmed_by',
        'cash_confirmed_at',
        'pos_machine_confirmed_amount',
        'pos_confirmed_by',
        'pos_confirmed_at',
        'pos_flagged',
        'pos_flag_note',
        'pos_ruling',
        'pos_ruling_note',
        'pos_ruled_by',
        'pos_ruled_at',
    ];

    /**
     * Statuses that mean "not yet confirmed" — a prior shift for the same
     * user sitting in one of these is what the shift-start gate blocks on
     * (Shift::hasUnsettledFor()). 'active' is deliberately excluded here:
     * a still-open shift isn't a settlement problem, it's just in progress
     * (though starting a new one while one is still active is its own,
     * separately-guarded case — see User::startShift()).
     */
    public const UNSETTLED_STATUSES = ['awaiting_cashier'];

    /**
     * A shift open longer than this is presumed abandoned/forgotten, not a
     * genuinely still-active custodian — it can no longer satisfy an
     * "active shift" guard or be treated as the current shift.
     *
     * There is no fixed shift schedule here (bartenders/chefs hand over
     * whenever, not on a clock) — 20 hours was too tight and falsely
     * treated a still-legitimate overnight shift as abandoned once it ran
     * past that mark. That silently broke two things at once: bar orders
     * (OrderSplitter gates on activeNonStale('bartender')) and the
     * handover screen itself (MyCount's hasActiveShift()/
     * otherActiveCustodian() route into the wrong flow once the real
     * custodian's own shift reads as stale). 72 hours (3 days) only catches
     * a shift that's genuinely been forgotten, not one still waiting on an
     * unscheduled handover.
     */
    public const STALE_AFTER_HOURS = 72;

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'starting_float' => 'decimal:2',
        'declared_cash' => 'decimal:2',
        'declared_pos' => 'decimal:2',
        'supervisor_confirmed_cash' => 'decimal:2',
        'supervisor_confirmed_pos' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'expected_pos' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'surplus_amount' => 'decimal:2',
        'settled_at' => 'datetime',
        'cashier_counted_cash' => 'decimal:2',
        'cash_confirmed_at' => 'datetime',
        'pos_machine_confirmed_amount' => 'decimal:2',
        'pos_confirmed_at' => 'datetime',
        'pos_flagged' => 'boolean',
        'pos_ruled_at' => 'datetime',
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

    public function cashConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_confirmed_by');
    }

    public function posConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pos_confirmed_by');
    }

    public function posRuledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pos_ruled_by');
    }

    /**
     * An open (unresolved) transfer dispute among this shift's own
     * OrderPayments — resolving one (any ruling) clears it here too,
     * since "ruling" being set is what "resolved" means.
     */
    public function hasOpenTransferFlag(): bool
    {
        return $this->payments()->where('flagged', true)->whereNull('ruling')->exists();
    }

    /**
     * The "parallel blocking condition" from the settlement spec — a
     * flagged POS-machine dispute or any unresolved flagged transfer for
     * this shift, either of which blocks confirmation and the owning
     * staff member's next shift-start (see Shift::hasUnsettledFor()).
     */
    public function hasOpenFlag(): bool
    {
        return $this->pos_flagged || $this->hasOpenTransferFlag();
    }

    /**
     * The shift-start gate: a prior settlement of this user's that is
     * still awaiting cashier confirmation (with or without an open flag —
     * a flag doesn't change the shift's own status, it's an orthogonal
     * signal checked via hasOpenFlag() elsewhere) blocks a new shift.
     */
    public static function hasUnsettledFor(int $userId): bool
    {
        return static::where('user_id', $userId)
            ->whereIn('status', self::UNSETTLED_STATUSES)
            ->exists();
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
