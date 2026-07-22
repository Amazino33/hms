<?php

use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollAcknowledgementService;
use App\Services\PayrollCompilationService;
use App\Services\PayrollPaymentService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

function paidLineFor(User $waiter, User $manager)
{
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 40000, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    return (new PayrollPaymentService())->markPaid($line, 'cash', null, null, $manager);
}

it('lets the paid staff member acknowledge their own payslip and closes the run once every line is settled', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    $line = paidLineFor($waiter, $manager);

    $service = new PayrollAcknowledgementService();
    $acked = $service->acknowledge($line, $waiter);

    expect($acked->status)->toBe('acknowledged');
    expect($acked->acknowledged_at)->not->toBeNull();
    expect($acked->run->fresh()->status)->toBe('closed');
});

it('refuses to let a different staff member acknowledge someone else\'s payslip', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $otherWaiter = User::factory()->create();
    $manager = User::factory()->create();

    $line = paidLineFor($waiter, $manager);

    $service = new PayrollAcknowledgementService();

    expect(fn () => $service->acknowledge($line, $otherWaiter))
        ->toThrow(RuntimeException::class);
});

it('lets the paid staff member dispute with a reason, and a dispute blocks the run from auto-closing', function () {
    seedPayrollRoles();
    $waiterA = User::factory()->create();
    $waiterA->assignRole('waiter');
    $waiterB = User::factory()->create();
    $waiterB->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiterA->id, 'amount' => 40000, 'effective_from' => '2026-07-01']);
    StaffSalary::create(['user_id' => $waiterB->id, 'amount' => 40000, 'effective_from' => '2026-07-01']);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $compiler->sealRun($run);

    $payment = new PayrollPaymentService();
    $lineA = $payment->markPaid($run->lines()->where('user_id', $waiterA->id)->first(), 'cash', null, null, $manager);
    $lineB = $payment->markPaid($run->lines()->where('user_id', $waiterB->id)->first(), 'cash', null, null, $manager);

    $service = new PayrollAcknowledgementService();
    $disputed = $service->dispute($lineA, $waiterA, 'I received less than this', 30000);

    expect($disputed->status)->toBe('disputed');
    expect($disputed->dispute_reason)->toBe('I received less than this');
    expect((float) $disputed->dispute_reported_amount)->toBe(30000.0);

    $service->acknowledge($lineB, $waiterB);

    expect($run->fresh()->status)->toBe('sealed');
});

it('lets a manager force-close a payslip without acknowledgement, with a required reason', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    $line = paidLineFor($waiter, $manager);

    $service = new PayrollAcknowledgementService();

    expect(fn () => $service->closeWithReason($line, $manager, ''))
        ->toThrow(RuntimeException::class);

    $closed = $service->closeWithReason($line, $manager, 'Staff left employment before acknowledging');

    expect($closed->status)->toBe('closed_no_ack');
    expect($closed->closed_by)->toBe($manager->id);
    expect($closed->run->fresh()->status)->toBe('closed');
});
