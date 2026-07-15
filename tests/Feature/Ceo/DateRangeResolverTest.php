<?php

use App\Services\Ceo\DateRange;
use App\Services\Ceo\DateRangeResolver;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('resolves today and yesterday presets', function () {
    CarbonImmutable::setTestNow('2026-07-15 10:00:00');
    $resolver = new DateRangeResolver();

    $today = $resolver->resolvePreset('today');
    expect($today->start->toDateString())->toBe('2026-07-15');
    expect($today->end->toDateString())->toBe('2026-07-15');
    expect($today->days())->toBe(1);

    $yesterday = $resolver->resolvePreset('yesterday');
    expect($yesterday->start->toDateString())->toBe('2026-07-14');
    expect($yesterday->end->toDateString())->toBe('2026-07-14');
});

it('caps this_week and this_month at today rather than the full calendar period', function () {
    CarbonImmutable::setTestNow('2026-07-15 10:00:00'); // a Wednesday
    $resolver = new DateRangeResolver();

    $week = $resolver->resolvePreset('this_week');
    expect($week->start->format('l'))->toBe('Monday');
    expect($week->end->toDateString())->toBe('2026-07-15');

    $month = $resolver->resolvePreset('this_month');
    expect($month->start->toDateString())->toBe('2026-07-01');
    expect($month->end->toDateString())->toBe('2026-07-15');
});

it('resolves last_month as the fully closed prior calendar month', function () {
    CarbonImmutable::setTestNow('2026-07-15');
    $resolver = new DateRangeResolver();

    $lastMonth = $resolver->resolvePreset('last_month');
    expect($lastMonth->start->toDateString())->toBe('2026-06-01');
    expect($lastMonth->end->toDateString())->toBe('2026-06-30');
});

it('computes previous_period as an equal-length range immediately before the primary', function () {
    $resolver = new DateRangeResolver();
    $primary = new DateRange(CarbonImmutable::parse('2026-07-10'), CarbonImmutable::parse('2026-07-15')); // 6 days

    $comparison = $resolver->resolveComparison('previous_period', $primary);

    expect($comparison->days())->toBe(6);
    expect($comparison->end->toDateString())->toBe('2026-07-09');
    expect($comparison->start->toDateString())->toBe('2026-07-04');
});

it('computes same_period_last_month by shifting both ends back one calendar month', function () {
    $resolver = new DateRangeResolver();
    $primary = new DateRange(CarbonImmutable::parse('2026-07-10'), CarbonImmutable::parse('2026-07-15'));

    $comparison = $resolver->resolveComparison('same_period_last_month', $primary);

    expect($comparison->start->toDateString())->toBe('2026-06-10');
    expect($comparison->end->toDateString())->toBe('2026-06-15');
});

it('computes a delta with an absolute amount and percent', function () {
    $resolver = new DateRangeResolver();

    $delta = $resolver->delta(150.0, 100.0);
    expect($delta['absolute'])->toBe(50.0);
    expect($delta['percent'])->toBe(50.0);
});

it('returns a null percent (not zero) when the previous value was zero', function () {
    $resolver = new DateRangeResolver();

    $delta = $resolver->delta(50.0, 0.0);
    expect($delta['absolute'])->toBe(50.0);
    expect($delta['percent'])->toBeNull();
});

it('computes per-day averages so unequal-length comparisons stay meaningful', function () {
    $resolver = new DateRangeResolver();
    $tenDays = new DateRange(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-10'));

    expect($resolver->perDayAverage(1000.0, $tenDays))->toBe(100.0);

    $threeDays = new DateRange(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-03'));
    expect($resolver->perDayAverage(300.0, $threeDays))->toBe(100.0);
});
