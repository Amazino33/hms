<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * A fixed, seeded list — Expense.expense_category_id must reference
     * one of these, never free text. firstOrCreate keeps this idempotent
     * and additive-only, matching this codebase's other seeders that run
     * against a live production database on every deploy.
     */
    public function run(): void
    {
        foreach ([
            'Salaries',
            'Power/Fuel',
            'Kitchen/Food Purchases',
            'Maintenance & Repairs',
            'Utilities',
            'Supplies',
            'Government/Levies',
            'Other',
        ] as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
