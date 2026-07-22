<?php

use App\Models\StaffDebt;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollCompilationService;
use App\Services\PayrollPaymentService;
use App\Services\PayrollVoidService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

it('voids a sealed run and drafts a fresh superseding run for the same period', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 40000, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $compiler->sealRun($run);

    $voidService = app(PayrollVoidService::class);
    $newRun = $voidService->voidAndReissue($run, 'Waiter disputed the amount paid', $manager);

    expect($run->fresh()->status)->toBe('voided');
    expect($run->fresh()->void_reason)->toBe('Waiter disputed the amount paid');
    expect($run->fresh()->voided_by)->toBe($manager->id);

    expect($newRun->status)->toBe('draft');
    expect($newRun->supersedes_id)->toBe($run->id);
    expect($newRun->period_start->toDateString())->toBe('2026-07-01');
    expect($newRun->lines()->where('user_id', $waiter->id)->exists())->toBeTrue();
});

it('does not let the reissued run double-count a debt already repaid via the voided run', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 40000, 'effective_from' => '2026-07-01']);
    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 5000, 'status' => 'open', 'created_by' => $manager->id]);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $line = $run->lines()->where('user_id', $waiter->id)->first();
    $compiler->setDeduction($line, $debt, 2000);
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    (new PayrollPaymentService())->markPaid($line, 'cash', null, null, $manager);

    expect((float) $debt->fresh()->remainingBalance())->toBe(3000.0);

    $voidService = app(PayrollVoidService::class);
    $newRun = $voidService->voidAndReissue($run, 'Correction needed', $manager);
    $newLine = $newRun->lines()->where('user_id', $waiter->id)->first();

    // The fresh line starts with no deduction — only 3000 remains available.
    expect((float) $newLine->deduction_amount)->toBe(0.0);

    expect(fn () => $compiler->setDeduction($newLine, $debt->fresh(), 3001))
        ->toThrow(RuntimeException::class);

    $compiler->setDeduction($newLine, $debt->fresh(), 3000);
    $newLine->refresh();

    expect((float) $newLine->deduction_amount)->toBe(3000.0);
});

it('refuses to void a draft run, and requires a non-empty reason', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 40000, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $draftRun = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);

    $voidService = app(PayrollVoidService::class);

    expect(fn () => $voidService->voidAndReissue($draftRun, 'reason', $manager))
        ->toThrow(RuntimeException::class);

    $sealedRun = $compiler->sealRun($draftRun);

    expect(fn () => $voidService->voidAndReissue($sealedRun, '', $manager))
        ->toThrow(RuntimeException::class);
});
