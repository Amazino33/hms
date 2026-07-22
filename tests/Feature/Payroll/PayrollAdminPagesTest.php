<?php

use App\Filament\Pages\PayrollRunDetail;
use App\Filament\Pages\PayrollRuns;
use App\Models\PagePermission;
use App\Models\PayrollLineDeduction;
use App\Models\PayrollRun;
use App\Models\StaffDebt;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollCompilationService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function grantPayrollAdminPagePermissions(string $role = 'manager'): void
{
    foreach ([PayrollRuns::class => 'Payroll Runs', PayrollRunDetail::class => 'Payroll Run Detail'] as $class => $name) {
        PagePermission::firstOrCreate(
            ['page_class' => $class, 'role_name' => $role],
            ['page_class' => $class, 'page_name' => $name, 'role_name' => $role],
        );
    }
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('lets a super admin reach the payroll runs list without any explicit grant', function () {
    Role::firstOrCreate(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get('/admin/payroll-runs')->assertStatus(200);
});

it('blocks a plain waiter from the payroll runs list', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)->get('/admin/payroll-runs')->assertStatus(403);
});

it('lets a manager draft a new payroll run from the list page and redirects to its detail page', function () {
    seedPayrollRoles();
    grantPayrollAdminPagePermissions();
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);

    Livewire::actingAs($manager)
        ->test(PayrollRuns::class)
        ->callTableAction('newRun', null, ['period_start' => '2026-07-01', 'period_end' => '2026-07-31'])
        ->assertRedirect();

    $run = PayrollRun::first();
    expect($run)->not->toBeNull();
    expect($run->status)->toBe('draft');
    expect($run->lines()->where('user_id', $waiter->id)->exists())->toBeTrue();
});

it('shows a draft run and lets a manager add and remove a deduction', function () {
    seedPayrollRoles();
    grantPayrollAdminPagePermissions();
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);
    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 5000, 'status' => 'open', 'created_by' => $manager->id]);

    $run = (new PayrollCompilationService())->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    $component = Livewire::actingAs($manager)
        ->test(PayrollRunDetail::class, ['run_id' => $run->id])
        ->assertSee($waiter->name)
        ->set("deductionDebtId.{$line->id}", $debt->id)
        ->set("deductionAmount.{$line->id}", 2000)
        ->call('addDeduction', $line->id);

    $line->refresh();
    expect((float) $line->deduction_amount)->toBe(2000.0);

    $deduction = PayrollLineDeduction::where('payroll_line_id', $line->id)->first();
    $component->call('removeDeduction', $deduction->id);

    $line->refresh();
    expect((float) $line->deduction_amount)->toBe(0.0);
});

it('lets a manager seal a draft run', function () {
    seedPayrollRoles();
    grantPayrollAdminPagePermissions();
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);

    $run = (new PayrollCompilationService())->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);

    Livewire::actingAs($manager)
        ->test(PayrollRunDetail::class, ['run_id' => $run->id])
        ->call('sealRun');

    expect($run->fresh()->status)->toBe('sealed');
});

it('lets a manager void and reissue a sealed run, landing on the new draft', function () {
    seedPayrollRoles();
    grantPayrollAdminPagePermissions();
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $compiler->sealRun($run);

    Livewire::actingAs($manager)
        ->test(PayrollRunDetail::class, ['run_id' => $run->id])
        ->set('voidReason', 'Wrong period compiled')
        ->call('voidAndReissue')
        ->assertRedirect();

    expect($run->fresh()->status)->toBe('voided');
    expect(PayrollRun::where('supersedes_id', $run->id)->exists())->toBeTrue();
});
