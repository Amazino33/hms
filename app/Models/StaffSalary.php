<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Effective-dated base salary history — append-only. A raise is a new
 * dated row, never an edit to an existing one.
 */
class StaffSalary extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_from' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('staff_salary')
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * The row in effect for $user on $date: greatest effective_from <= date,
     * newest created_at wins on ties. Mirrors DailyBusinessSnapshot::latestFor().
     */
    public static function effectiveFor(User $user, CarbonImmutable|string $date): ?self
    {
        $date = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return static::where('user_id', $user->id)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
