<?php

namespace App\Services\Ceo;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;

/**
 * Resolves the dashboard/report filter bar's preset + comparison inputs
 * into concrete DateRange pairs. Kept deliberately dumb and stateless —
 * everything downstream (widgets, report services) works off the
 * resolved DateRange, never off the raw preset string, so the "today"-
 * relative presets (this_week/this_month) are resolved exactly once per
 * request, not re-evaluated differently in different places.
 *
 * "Today" here means the current business day (BusinessDay::today(),
 * 4am WAT boundary) — not the calendar day in the app's UTC server
 * timezone.
 */
class DateRangeResolver
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'custom'];

    public const COMPARISONS = ['off', 'previous_period', 'same_period_last_month', 'custom'];

    public function resolvePreset(string $preset, ?string $customFrom = null, ?string $customTo = null): DateRange
    {
        $today = CarbonImmutable::parse(BusinessDay::today());

        return match ($preset) {
            'today' => new DateRange($today, $today),
            'yesterday' => new DateRange($today->subDay(), $today->subDay()),
            // Capped at today, not the full calendar week/month — a CEO
            // report showing "revenue" for days that haven't happened yet
            // would just be a row of zeros pulling every average down.
            'this_week' => new DateRange($today->startOfWeek(CarbonImmutable::MONDAY), $today),
            'this_month' => new DateRange($today->startOfMonth(), $today),
            'last_month' => new DateRange(
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth()
            ),
            'custom' => new DateRange(
                CarbonImmutable::parse($customFrom ?? $today->toDateString())->startOfDay(),
                CarbonImmutable::parse($customTo ?? $today->toDateString())->startOfDay()
            ),
            default => new DateRange($today, $today),
        };
    }

    public function resolveComparison(
        string $mode,
        DateRange $primary,
        ?string $customFrom = null,
        ?string $customTo = null
    ): ?DateRange {
        return match ($mode) {
            'previous_period' => new DateRange(
                $primary->start->subDays($primary->days()),
                $primary->start->subDay()
            ),
            'same_period_last_month' => new DateRange(
                $primary->start->subMonthNoOverflow(),
                $primary->end->subMonthNoOverflow()
            ),
            'custom' => $customFrom && $customTo
                ? new DateRange(CarbonImmutable::parse($customFrom)->startOfDay(), CarbonImmutable::parse($customTo)->startOfDay())
                : null,
            default => null,
        };
    }

    /**
     * @return array{absolute: float, percent: ?float} percent is null (not
     *   0) when the previous value was zero — "0% change from nothing" is
     *   meaningless and must be rendered as "n/a", not a false zero.
     */
    public function delta(float $current, float $previous): array
    {
        $absolute = $current - $previous;
        $percent = abs($previous) > 0.0001 ? ($absolute / abs($previous)) * 100 : null;

        return ['absolute' => $absolute, 'percent' => $percent];
    }

    public function perDayAverage(float $total, DateRange $range): float
    {
        $days = $range->days();

        return $days > 0 ? $total / $days : 0.0;
    }
}
