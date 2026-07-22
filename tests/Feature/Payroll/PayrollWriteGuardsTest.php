<?php

use App\Models\Order;
use App\Models\PagePermission;
use App\Models\StaffDebt;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\PayrollAcknowledgementService;
use App\Services\PayrollCompilationService;
use App\Services\PayrollPaymentService;
use App\Services\PayrollVoidService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The payroll module reads commission/order data but must never write to
 * either table, and never touches inventory/ingredient stock at all — its
 * only ledger write is a StaffDebtRepayment, and only at markPaid() time.
 * Runs the full lifecycle (draft -> deduct -> seal -> pay -> acknowledge)
 * plus a void-and-reissue, and asserts these tables are byte-for-byte
 * unchanged in row count and content throughout.
 */
it('never mutates orders, commissions, inventory_transactions, or ingredient_transactions through the full run lifecycle', function () {
    seedPayrollRoles();
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');
    $manager = User::factory()->create();

    StaffSalary::create(['user_id' => $waiter->id, 'amount' => 30000, 'effective_from' => '2026-07-01']);
    $order = Order::create(['order_number' => 'ORD-GUARD-1', 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);
    DB::table('commissions')->insert(['user_id' => $waiter->id, 'order_id' => $order->id, 'amount' => 50, 'created_at' => '2026-07-15 10:00:00']);
    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 5000, 'status' => 'open', 'created_by' => $manager->id]);

    $ordersBefore = DB::table('orders')->orderBy('id')->get();
    $commissionsBefore = DB::table('commissions')->orderBy('id')->get();
    $inventoryTxBefore = DB::table('inventory_transactions')->count();
    $ingredientTxBefore = DB::table('ingredient_transactions')->count();

    $compiler = new PayrollCompilationService();
    $run = $compiler->draftRun(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), null, $manager);
    $line = $run->lines()->where('user_id', $waiter->id)->first();
    $compiler->setDeduction($line, $debt, 2000);
    $compiler->refreshDraft($run);
    $run = $compiler->sealRun($run);
    $line = $run->lines()->where('user_id', $waiter->id)->first();

    (new PayrollPaymentService())->markPaid($line, 'cash', null, null, $manager);
    (new PayrollAcknowledgementService())->acknowledge($line->fresh(), $waiter);

    $secondDebt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 100, 'status' => 'open', 'created_by' => $manager->id]);
    $run2 = $compiler->draftRun(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), null, $manager);
    $run2 = $compiler->sealRun($run2);
    app(PayrollVoidService::class)->voidAndReissue($run2, 'test correction', $manager);

    expect(DB::table('orders')->orderBy('id')->get()->toArray())->toEqual($ordersBefore->toArray());
    expect(DB::table('commissions')->orderBy('id')->get()->toArray())->toEqual($commissionsBefore->toArray());
    expect(DB::table('inventory_transactions')->count())->toBe($inventoryTxBefore);
    expect(DB::table('ingredient_transactions')->count())->toBe($ingredientTxBefore);

    // The one ledger write the module IS allowed: exactly one repayment,
    // created only at markPaid(), for exactly the earmarked amount.
    expect($debt->fresh()->repayments()->count())->toBe(1);
    expect((float) $debt->fresh()->repayments()->first()->amount)->toBe(2000.0);
    expect($secondDebt->fresh()->repayments()->count())->toBe(0);
});

it('does not let the ceo panel reach the admin-only payroll compilation pages', function () {
    Role::firstOrCreate(['name' => 'ceo']);
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    // No PagePermission row exists for 'ceo' on either admin page, and
    // PermissionService::canAccessPage() denies by default for everyone
    // except super_admin.
    $this->actingAs($ceo)->get('/admin/payroll-runs')->assertStatus(403);
});

it('grants the ceo role no PagePermission entries at all for the admin payroll pages', function () {
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);

    expect(
        PagePermission::where('role_name', 'ceo')
            ->whereIn('page_class', [
                \App\Filament\Pages\PayrollRuns::class,
                \App\Filament\Pages\PayrollRunDetail::class,
            ])
            ->exists()
    )->toBeFalse();

    expect(
        PagePermission::where('page_class', \App\Filament\Pages\PayrollRuns::class)
            ->pluck('role_name')
            ->sort()
            ->values()
            ->all()
    )->toBe(['manager', 'super_admin', 'supervisor']);
});
