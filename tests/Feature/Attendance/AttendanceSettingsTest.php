<?php

use App\Models\Attendance\AttendanceSetting;
use App\Models\User;
use Carbon\CarbonImmutable;

it('keeps every version instead of editing the live one', function () {
    $march = AttendanceSetting::create(['effective_from' => '2026-03-01', 'fine_late' => 500]);
    $june = AttendanceSetting::create(['effective_from' => '2026-06-01', 'fine_late' => 750]);

    // The March row is the basis of every fine raised in March and must read
    // the same in June as it did then.
    expect($march->fresh()->fine_late)->toBe(500);
    expect($june->fresh()->fine_late)->toBe(750);
    expect(AttendanceSetting::count())->toBe(2);
});

it('resolves the version in force either side of an effective date', function () {
    AttendanceSetting::create(['effective_from' => '2026-03-01', 'fine_late' => 500]);
    AttendanceSetting::create(['effective_from' => '2026-06-01', 'fine_late' => 750]);

    expect(AttendanceSetting::forDate(CarbonImmutable::parse('2026-05-31'))->fine_late)->toBe(500);
    // Effective from is inclusive — the new figure applies on the day itself.
    expect(AttendanceSetting::forDate(CarbonImmutable::parse('2026-06-01'))->fine_late)->toBe(750);
    expect(AttendanceSetting::forDate(CarbonImmutable::parse('2026-07-15'))->fine_late)->toBe(750);
});

it('returns null for a date before any version existed', function () {
    AttendanceSetting::create(['effective_from' => '2026-03-01']);

    // Deliberately null rather than a default: there is no honest answer to
    // "what were the rules before anyone set any?".
    expect(AttendanceSetting::forDate(CarbonImmutable::parse('2026-02-28')))->toBeNull();
});

it('carries the locked default fines and thresholds', function () {
    $settings = AttendanceSetting::create(['effective_from' => '2026-03-01']);

    expect($settings->grace_minutes)->toBe(15);
    expect($settings->duplicate_punch_window_minutes)->toBe(30);
    expect($settings->fine_late)->toBe(500);
    expect($settings->fine_late_relief)->toBe(1000);
    expect($settings->fine_early_leave)->toBe(1500);
    expect($settings->fine_no_clockout)->toBe(1500);
    expect($settings->fine_absent)->toBe(3000);
});

it('starts in shadow mode with no announced start date', function () {
    $settings = AttendanceSetting::create(['effective_from' => '2026-03-01']);

    // Nothing may be charged to anybody until the owner turns this off.
    expect($settings->shadow_mode)->toBeTrue();
    expect($settings->rules_start_date)->toBeNull();
    expect($settings->absence_day_pay_amount)->toBeNull();
});

it('stores naira as whole integers', function () {
    $settings = AttendanceSetting::create([
        'effective_from' => '2026-03-01',
        'fine_late' => 500,
        'absence_day_pay_amount' => 2500,
    ]);

    expect($settings->fresh()->fine_late)->toBeInt();
    expect($settings->fresh()->absence_day_pay_amount)->toBeInt();
});

it('refuses two versions on the same effective date', function () {
    AttendanceSetting::create(['effective_from' => '2026-03-01']);

    expect(fn () => AttendanceSetting::create(['effective_from' => '2026-03-01']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('records who created a version', function () {
    $admin = User::factory()->create();

    $settings = AttendanceSetting::create([
        'effective_from' => '2026-03-01',
        'created_by' => $admin->id,
    ]);

    expect($settings->creator->is($admin))->toBeTrue();
});
