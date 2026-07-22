<?php

use App\Filament\Pages\MyPayslips;
use App\Models\PagePermission;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollCompilationService;
use App\Services\PayrollPaymentService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function grantMyPayslipsPagePermission(string $role): void
{
    PagePermission::firstOrCreate(
        ['page_class' => MyPayslips::class, 'role_name' => $role],
        ['page_class' => MyPayslips::class, 'page_name' => 'My Payslips', 'role_name' => $role],
    );
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('shows only the logged-in staff member their own paid payslip, not a colleague\'s', function () {
    seedPayrollRoles();
    grantMyPayslipsPagePermission('waiter');
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $otherWaiter = User::factory()->create();
    $otherWaiter->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);
    StaffSalary::create(['user_id' => $otherWaiter->id, 'amount' => 99999, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $compiler->sealRun($run);

    $payment = new PayrollPaymentService();
    $payment->markPaid($run->lines()->where('user_id', $waiter->id)->first(), 'cash', null, null, $manager);
    $payment->markPaid($run->lines()->where('user_id', $otherWaiter->id)->first(), 'cash', null, null, $manager);

    $html = Livewire::actingAs($waiter)->test(MyPayslips::class)->html();

    expect($html)->toContain('30,000.00');
    expect($html)->not->toContain('99,999.00');
});

it('lets the paid staff member acknowledge their own payslip via the page', function () {
    seedPayrollRoles();
    grantMyPayslipsPagePermission('waiter');
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();
    (new PayrollPaymentService())->markPaid($line, 'cash', null, null, $manager);

    Livewire::actingAs($waiter)
        ->test(MyPayslips::class)
        ->call('acknowledge', $line->id);

    expect($line->fresh()->status)->toBe('acknowledged');
});

it('lets the paid staff member dispute their payslip with a reason via the page', function () {
    seedPayrollRoles();
    grantMyPayslipsPagePermission('waiter');
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();
    (new PayrollPaymentService())->markPaid($line, 'cash', null, null, $manager);

    Livewire::actingAs($waiter)
        ->test(MyPayslips::class)
        ->set("disputeReason.{$line->id}", 'I got less than this')
        ->set("disputeReportedAmount.{$line->id}", 25000)
        ->call('dispute', $line->id);

    $line->refresh();
    expect($line->status)->toBe('disputed');
    expect($line->dispute_reason)->toBe('I got less than this');
    expect((float) $line->dispute_reported_amount)->toBe(25000.0);
});

it('blocks a waiter without the page permission grant', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)->get('/admin/my-payslips')->assertStatus(403);
});
