<?php

use App\Models\PayrollRun;
use App\Models\StaffDebt;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollCompilationService;
use App\Services\PayrollPaymentService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

function draftSingleWaiterRun(User $waiter, User $manager, float $salary = 50000): PayrollRun
{
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => $salary, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);

    return $run;
}

it('books a StaffDebtRepayment for each line deduction when marked paid, and never before', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 5000, 'status' => 'open', 'created_by' => $manager->id]);

    $run = draftSingleWaiterRun($waiter, $manager);
    $compiler = new PayrollCompilationService();
    $line = $run->lines()->where('user_id', $waiter->id)->first();
    $compiler->setDeduction($line, $debt, 2000);

    expect($debt->repayments()->count())->toBe(0);

    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    $payment = new PayrollPaymentService();
    $paid = $payment->markPaid($line, 'cash', null, null, $manager);

    expect($paid->status)->toBe('paid');
    expect($paid->paid_by)->toBe($manager->id);
    expect($debt->fresh()->repayments()->count())->toBe(1);

    $repayment = $debt->fresh()->repayments()->first();
    expect((float) $repayment->amount)->toBe(2000.0);
    expect($repayment->method)->toBe('salary_deduction');
    expect((float) $debt->fresh()->remainingBalance())->toBe(3000.0);

    $deduction = $paid->deductions()->first();
    expect($deduction->staff_debt_repayment_id)->toBe($repayment->id);
});

it('refuses to mark paid a line on a draft (unsealed) run', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    $run = draftSingleWaiterRun($waiter, $manager);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    $payment = new PayrollPaymentService();

    expect(fn () => $payment->markPaid($line, 'cash', null, null, $manager))
        ->toThrow(RuntimeException::class);
});

it('refuses to mark an already-paid line paid again', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    $run = draftSingleWaiterRun($waiter, $manager);
    $compiler = new PayrollCompilationService();
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    $payment = new PayrollPaymentService();
    $payment->markPaid($line, 'cash', null, null, $manager);

    expect(fn () => $payment->markPaid($line->fresh(), 'cash', null, null, $manager))
        ->toThrow(RuntimeException::class);
});

it('refuses an invalid payment method', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    $run = draftSingleWaiterRun($waiter, $manager);
    $compiler = new PayrollCompilationService();
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    $payment = new PayrollPaymentService();

    expect(fn () => $payment->markPaid($line, 'crypto', null, null, $manager))
        ->toThrow(RuntimeException::class);
});
