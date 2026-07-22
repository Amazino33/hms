<?php

use App\Models\PagePermission;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollCompilationService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

/**
 * "supervisor" is a real, separate Spatie role on at least one production
 * deployment — a custom role added directly there, distinct from "manager"
 * and not part of this codebase's documented role list. This file locks in
 * two things: eligibleStaff() must never throw just because some install
 * doesn't have that role, and wherever it DOES exist, a supervisor is a
 * full payroll participant (a payslip recipient, not just an administrator).
 */
afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('does not throw when the custom "supervisor" role does not exist in this install', function () {
    seedPayrollRoles();
    $manager = User::factory()->create();

    $run = (new PayrollCompilationService())->draftRun(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
        null,
        $manager,
    );

    expect($run->status)->toBe('draft');
});

it('generates a payroll line for a user holding the separate "supervisor" role', function () {
    seedPayrollRoles();
    Role::firstOrCreate(['name' => 'supervisor']);

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    StaffSalary::create(['user_id' => $supervisor->id, 'amount' => 60000, 'effective_from' => '2026-07-01']);

    $manager = User::factory()->create();

    $run = (new PayrollCompilationService())->draftRun(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
        null,
        $manager,
    );

    $line = $run->lines()->where('user_id', $supervisor->id)->first();
    expect($line)->not->toBeNull();
    expect((float) $line->base_amount)->toBe(60000.0);
});

it('grants the supervisor role admin payroll pages and My Payslips via the seeder', function () {
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);

    foreach ([
        \App\Filament\Pages\PayrollRuns::class,
        \App\Filament\Pages\PayrollRunDetail::class,
        \App\Filament\Pages\MyPayslips::class,
    ] as $pageClass) {
        expect(
            PagePermission::where('page_class', $pageClass)->where('role_name', 'supervisor')->exists()
        )->toBeTrue("Expected a 'supervisor' PagePermission grant for {$pageClass}.");
    }
});

it('lets a supervisor-role user reach the admin payroll pages once granted', function () {
    Role::firstOrCreate(['name' => 'supervisor']);
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');

    $this->actingAs($supervisor)->get('/admin/payroll-runs')->assertStatus(200);
    $this->actingAs($supervisor)->get('/admin/my-payslips')->assertStatus(200);
});
