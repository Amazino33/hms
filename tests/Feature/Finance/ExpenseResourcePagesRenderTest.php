<?php

use App\Filament\Resources\ExpenseCategories\Pages\CreateExpenseCategory;
use App\Filament\Resources\ExpenseCategories\Pages\EditExpenseCategory;
use App\Filament\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Pure render smoke tests — catches Filament Schema/Table API mismatches
 * (wrong import, renamed component, etc.) that the service-level tests
 * can't, since those never touch the actual Resource/Page classes.
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'ShieldSeeder', '--force' => true]);
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
});

it('renders the Expense list and create pages without error', function () {
    ExpenseCategory::create(['name' => 'Utilities', 'is_active' => true]);

    Livewire::actingAs($this->superAdmin)
        ->test(ListExpenses::class)
        ->assertOk();

    Livewire::actingAs($this->superAdmin)
        ->test(CreateExpense::class)
        ->assertOk();
});

it('renders the ExpenseCategory list, create, and edit pages without error', function () {
    $category = ExpenseCategory::create(['name' => 'Utilities', 'is_active' => true]);

    Livewire::actingAs($this->superAdmin)
        ->test(ListExpenseCategories::class)
        ->assertOk();

    Livewire::actingAs($this->superAdmin)
        ->test(CreateExpenseCategory::class)
        ->assertOk();

    Livewire::actingAs($this->superAdmin)
        ->test(EditExpenseCategory::class, ['record' => $category->id])
        ->assertOk();
});

it('actually creates an expense through the real Create page form', function () {
    $category = ExpenseCategory::create(['name' => 'Supplies', 'is_active' => true]);

    Livewire::actingAs($this->superAdmin)
        ->test(CreateExpense::class)
        ->fillForm([
            'amount' => 2500,
            'expense_category_id' => $category->id,
            'date_incurred' => now()->toDateString(),
            'note' => 'Cleaning supplies',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::where('note', 'Cleaning supplies')->exists())->toBeTrue();
});
