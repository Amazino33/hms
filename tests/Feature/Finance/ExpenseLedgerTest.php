<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ExpenseService;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

/**
 * Financial Foundations Part C: an append-only expense ledger. amount/
 * category/date_incurred are immutable once posted — a correction voids
 * the row (kept forever, excluded from totals) rather than editing it.
 * Procurement is explicitly out of scope here (captured elsewhere already).
 */
it('records a valid expense, attributing it to the acting user', function () {
    $category = ExpenseCategory::create(['name' => 'Utilities', 'is_active' => true]);
    $manager = User::factory()->create();

    $expense = app(ExpenseService::class)->create(
        ['amount' => 15000, 'expense_category_id' => $category->id, 'note' => 'NEPA bill'],
        $manager->id,
    );

    expect((float) $expense->amount)->toBe(15000.0);
    expect($expense->entered_by)->toBe($manager->id);
    expect($expense->date_incurred->toDateString())->toBe(now()->toDateString());
    expect($expense->isVoided())->toBeFalse();
});

it('allows backdating date_incurred', function () {
    $category = ExpenseCategory::create(['name' => 'Utilities', 'is_active' => true]);

    $expense = app(ExpenseService::class)->create(
        ['amount' => 5000, 'expense_category_id' => $category->id, 'date_incurred' => '2026-01-05'],
        User::factory()->create()->id,
    );

    expect($expense->date_incurred->toDateString())->toBe('2026-01-05');
});

it('rejects a zero or negative amount', function () {
    $category = ExpenseCategory::create(['name' => 'Utilities', 'is_active' => true]);

    expect(fn () => app(ExpenseService::class)->create(
        ['amount' => 0, 'expense_category_id' => $category->id],
        User::factory()->create()->id,
    ))->toThrow(Exception::class);
});

it('rejects an expense against a deactivated category', function () {
    $category = ExpenseCategory::create(['name' => 'Old Category', 'is_active' => false]);

    expect(fn () => app(ExpenseService::class)->create(
        ['amount' => 1000, 'expense_category_id' => $category->id],
        User::factory()->create()->id,
    ))->toThrow(Exception::class);
});

it('rejects an invalid category id — no free-text categories', function () {
    expect(fn () => app(ExpenseService::class)->create(
        ['amount' => 1000, 'expense_category_id' => 999999],
        User::factory()->create()->id,
    ))->toThrow(Exception::class);
});

it('lets note be edited after creation, but nothing else changes', function () {
    $category = ExpenseCategory::create(['name' => 'Supplies', 'is_active' => true]);
    $expense = app(ExpenseService::class)->create(
        ['amount' => 2000, 'expense_category_id' => $category->id, 'note' => 'original'],
        User::factory()->create()->id,
    );

    $updated = app(ExpenseService::class)->updateNote($expense, 'corrected note');

    expect($updated->note)->toBe('corrected note');
    expect((float) $updated->amount)->toBe(2000.0);
    expect($updated->expense_category_id)->toBe($category->id);
});

it('voids an expense with a required reason, and a voided expense is excluded from a totals query', function () {
    $category = ExpenseCategory::create(['name' => 'Supplies', 'is_active' => true]);
    $manager = User::factory()->create();

    $good = app(ExpenseService::class)->create(['amount' => 3000, 'expense_category_id' => $category->id], User::factory()->create()->id);
    $mistake = app(ExpenseService::class)->create(['amount' => 9999, 'expense_category_id' => $category->id], User::factory()->create()->id);

    expect((float) Expense::notVoided()->sum('amount'))->toBe(12999.0);

    app(ExpenseService::class)->void($mistake, $manager->id, 'Entered under the wrong category by mistake');

    // Still visible — just excluded from the total.
    expect(Expense::count())->toBe(2);
    expect((float) Expense::notVoided()->sum('amount'))->toBe(3000.0);
    expect($mistake->fresh()->isVoided())->toBeTrue();
    expect($mistake->fresh()->voided_by)->toBe($manager->id);
    expect($good->fresh()->isVoided())->toBeFalse();
});

it('requires a reason to void, and refuses to void the same expense twice', function () {
    $category = ExpenseCategory::create(['name' => 'Supplies', 'is_active' => true]);
    $expense = app(ExpenseService::class)->create(['amount' => 1000, 'expense_category_id' => $category->id], User::factory()->create()->id);
    $manager = User::factory()->create();

    expect(fn () => app(ExpenseService::class)->void($expense, $manager->id, ''))->toThrow(Exception::class);

    app(ExpenseService::class)->void($expense->fresh(), $manager->id, 'duplicate entry');

    expect(fn () => app(ExpenseService::class)->void($expense->fresh(), $manager->id, 'again'))->toThrow(Exception::class);
});

it('grants super_admin and manager access to the Expense resource, via Shield permissions', function () {
    Artisan::call('db:seed', ['--class' => 'ShieldSeeder', '--force' => true]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    expect($superAdmin->can('viewAny', Expense::class))->toBeTrue();
    expect($manager->can('viewAny', Expense::class))->toBeTrue();
    expect($waiter->can('viewAny', Expense::class))->toBeFalse();
});

it('restricts the ExpenseCategory resource to super_admin only, not manager', function () {
    Artisan::call('db:seed', ['--class' => 'ShieldSeeder', '--force' => true]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    expect($superAdmin->can('viewAny', ExpenseCategory::class))->toBeTrue();
    expect($manager->can('viewAny', ExpenseCategory::class))->toBeFalse();
});

it('seeds the fixed category list idempotently, without duplicating on a second run', function () {
    Artisan::call('db:seed', ['--class' => 'ExpenseCategorySeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'ExpenseCategorySeeder', '--force' => true]);

    expect(ExpenseCategory::count())->toBe(8);
    expect(ExpenseCategory::where('name', 'Salaries')->count())->toBe(1);
});
