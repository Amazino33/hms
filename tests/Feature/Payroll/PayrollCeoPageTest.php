<?php

use App\Filament\Ceo\Pages\Payroll;
use App\Models\StaffDebt;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollCompilationService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

beforeEach(function () {
    Role::firstOrCreate(['name' => 'ceo']);
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('ceo'));
});

it('lets the ceo role reach the payroll page, and blocks a plain waiter', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $this->actingAs($ceo)->get('/ceo/payroll')->assertStatus(200);

    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $this->actingAs($waiter)->get('/ceo/payroll')->assertStatus(403);
});

it('lets the ceo mark a pending line paid, booking any earmarked deduction as a repayment', function () {
    seedPayrollRoles();
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);
    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 5000, 'status' => 'open', 'created_by' => $ceo->id]);

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $ceo);
    $line = $run->lines()->where('user_id', $waiter->id)->first();
    $compiler->setDeduction($line, $debt, 2000);
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    Livewire::actingAs($ceo)
        ->test(Payroll::class)
        ->call('selectRun', $run->id)
        ->set("paymentMethod.{$line->id}", 'transfer')
        ->set("paymentReference.{$line->id}", 'TXN-001')
        ->call('markPaid', $line->id);

    $line->refresh();
    expect($line->status)->toBe('paid');
    expect($line->payment_method)->toBe('transfer');
    expect($line->payment_reference)->toBe('TXN-001');
    expect((float) $debt->fresh()->remainingBalance())->toBe(3000.0);
});

it('does not let the ceo mark a pending line paid on a draft run', function () {
    seedPayrollRoles();
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);

    $run = (new PayrollCompilationService())->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $ceo);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    // The CEO page only ever lists sealed/closed/voided runs, so a draft
    // run's line is simply never reachable via selectRun() — it isn't in
    // runs() at all.
    Livewire::actingAs($ceo)
        ->test(Payroll::class)
        ->call('selectRun', $run->id)
        ->call('markPaid', $line->id);

    expect($line->fresh()->status)->toBe('pending');
});
