<?php

use App\Models\Order;
use App\Models\PayrollRun;
use App\Models\StaffDebt;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollCompilationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
    Cache::forget('setting:payroll_minimum_net');
});

function makeCommission(User $user, Order $order, float $amount, string $createdAt): void
{
    DB::table('commissions')->insert([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'amount' => $amount,
        'created_at' => $createdAt,
    ]);
}

it('drafts one line per eligible active staff member, excluding super_admin/ceo and left staff', function () {
    seedPayrollRoles();
    Role::firstOrCreate(['name' => 'super_admin']);
    Role::firstOrCreate(['name' => 'ceo']);

    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);

    $manager = User::factory()->create();
    $manager->assignRole('manager');
    StaffSalary::create(['user_id' => $manager->id, 'amount' => 80000, 'effective_from' => '2026-07-01']);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $leftWaiter = User::factory()->create(['left_at' => now()->subDay()]);
    $leftWaiter->assignRole('waiter');

    $service = new PayrollCompilationService();
    $run = $service->draftRun(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
        null,
        $manager,
    );

    $userIds = $run->lines()->pluck('user_id')->all();

    expect($userIds)->toContain($waiter->id);
    expect($userIds)->toContain($manager->id);
    expect($userIds)->not->toContain($superAdmin->id);
    expect($userIds)->not->toContain($leftWaiter->id);
});

it('sums commission only from orders not returned/cancelled, within the period', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 20000, 'effective_from' => '2026-07-01']);
    $manager = User::factory()->create();

    $paidOrder = Order::create(['order_number' => 'ORD-1', 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);
    $returnedOrder = Order::create(['order_number' => 'ORD-2', 'user_id' => $waiter->id, 'status' => 'returned', 'total_amount' => 1000, 'amount_paid' => 1000]);
    $cancelledOrder = Order::create(['order_number' => 'ORD-3', 'user_id' => $waiter->id, 'status' => 'cancelled', 'total_amount' => 1000, 'amount_paid' => 1000]);
    $outsidePeriodOrder = Order::create(['order_number' => 'ORD-4', 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);

    makeCommission($waiter, $paidOrder, 50, '2026-07-15 10:00:00');
    makeCommission($waiter, $returnedOrder, 60, '2026-07-16 10:00:00');
    makeCommission($waiter, $cancelledOrder, 70, '2026-07-17 10:00:00');
    makeCommission($waiter, $outsidePeriodOrder, 999, '2026-06-20 10:00:00'); // outside period

    $service = new PayrollCompilationService();
    $run = $service->draftRun(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
        null,
        $manager,
    );

    $line = $run->lines()->where('user_id', $waiter->id)->first();

    expect((float) $line->commission_amount)->toBe(50.0);
    expect((float) $line->gross_amount)->toBe(20050.0);
});

it('rejects a deduction exceeding the debt outstanding balance', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 50000, 'effective_from' => '2026-07-01']);
    $manager = User::factory()->create();

    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 500, 'status' => 'open', 'created_by' => $manager->id]);

    $service = new PayrollCompilationService();
    $run = $service->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    expect(fn () => $service->setDeduction($line, $debt, 600))
        ->toThrow(RuntimeException::class);

    $service->setDeduction($line, $debt, 500);
    $line->refresh();

    expect((float) $line->deduction_amount)->toBe(500.0);
    expect((float) $line->net_amount)->toBe(49500.0);
});

it('rejects a deduction that would push net pay below the configured floor', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 15000, 'effective_from' => '2026-07-01']);
    $manager = User::factory()->create();

    App\Services\SettingsService::set('payroll_minimum_net', '10000', 'string', $manager->id);

    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 10000, 'status' => 'open', 'created_by' => $manager->id]);

    $service = new PayrollCompilationService();
    $run = $service->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    expect(fn () => $service->setDeduction($line, $debt, 8000))
        ->toThrow(RuntimeException::class);
});

it('replaces an existing deduction for the same debt rather than stacking a second row', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 50000, 'effective_from' => '2026-07-01']);
    $manager = User::factory()->create();

    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 5000, 'status' => 'open', 'created_by' => $manager->id]);

    $service = new PayrollCompilationService();
    $run = $service->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    $service->setDeduction($line, $debt, 1000);
    $service->setDeduction($line, $debt, 2000);
    $line->refresh();

    expect($line->deductions()->count())->toBe(1);
    expect((float) $line->deduction_amount)->toBe(2000.0);
});

it('freezes money columns at seal — a commission added afterward is not picked up', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 20000, 'effective_from' => '2026-07-01']);
    $manager = User::factory()->create();

    $order = Order::create(['order_number' => 'ORD-9', 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);
    makeCommission($waiter, $order, 100, '2026-07-10 10:00:00');

    $service = new PayrollCompilationService();
    $run = $service->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $run = $service->sealRun($run);

    $lateOrder = Order::create(['order_number' => 'ORD-10', 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);
    makeCommission($waiter, $lateOrder, 5000, '2026-07-20 10:00:00');

    $line = PayrollRun::find($run->id)->lines()->where('user_id', $waiter->id)->first();

    expect((float) $line->commission_amount)->toBe(100.0);
    expect($run->status)->toBe('sealed');
});

it('refuses to seal an empty run or a run that is not a draft', function () {
    $manager = User::factory()->create();
    $service = new PayrollCompilationService();

    $emptyRun = PayrollRun::create([
        'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'draft', 'prepared_by' => $manager->id,
    ]);

    expect(fn () => $service->sealRun($emptyRun))->toThrow(RuntimeException::class);

    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 20000, 'effective_from' => '2026-07-01']);

    $run = $service->draftRun(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), null, $manager);
    $run = $service->sealRun($run);

    expect(fn () => $service->sealRun($run))->toThrow(RuntimeException::class);
});
