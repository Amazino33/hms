<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Append-only and immutable. A correction to a past business day never
 * updates or deletes a row — it inserts a new row for the same
 * business_date with supersedes_id pointing at the row it replaces.
 * Readers always want the latest row per date: use latestFor()/
 * latestForRange(), never a plain where('business_date', ...)->first().
 */
class DailyBusinessSnapshot extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'computed_at' => 'datetime',
        'revenue_earned_total' => 'decimal:2',
        'revenue_bar' => 'decimal:2',
        'revenue_restaurant' => 'decimal:2',
        'revenue_rooms' => 'decimal:2',
        'cogs_total' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'damages_cost_total' => 'decimal:2',
        'cash_collected_total' => 'decimal:2',
        'cash_collected_cash' => 'decimal:2',
        'cash_collected_pos' => 'decimal:2',
        'cash_collected_transfers_verified' => 'decimal:2',
        'cash_collected_transfers_unverified' => 'decimal:2',
        'gap_total' => 'decimal:2',
        'gap_unverified_transfers' => 'decimal:2',
        'gap_open_folio_balance' => 'decimal:2',
        'gap_unsettled_shift_amount' => 'decimal:2',
        'gap_staff_debt_outstanding' => 'decimal:2',
        'staff_debt_new' => 'decimal:2',
        'staff_debt_repaid' => 'decimal:2',
        'expenses_total' => 'decimal:2',
        'occupancy_rate' => 'decimal:2',
        'adr' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('daily_business_snapshot')
            ->dontLogEmptyChanges();
    }

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * The current row for a single business date — the latest one
     * inserted, following the supersedes_id chain implicitly (the newest
     * created_at always wins, whether or not it names its predecessor).
     */
    public static function latestFor(string $businessDate): ?self
    {
        // whereDate(), not where() — the date cast doesn't guarantee the
        // stored column is a bare Y-m-d string on every driver (SQLite
        // stores it with a "00:00:00" suffix), so a plain string-equality
        // where() silently matches nothing.
        return static::whereDate('business_date', $businessDate)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The current row per date across an inclusive [$from, $to] range,
     * keyed by business_date (Y-m-d), latest-per-date only.
     */
    public static function latestForRange(string $from, string $to): \Illuminate\Support\Collection
    {
        return static::whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (self $s) => $s->business_date->toDateString())
            ->sortBy(fn (self $s) => $s->business_date->toDateString())
            ->values();
    }
}
