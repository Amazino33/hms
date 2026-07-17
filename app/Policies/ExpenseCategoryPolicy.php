<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpenseCategory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExpenseCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExpenseCategory');
    }

    public function view(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('View:ExpenseCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExpenseCategory');
    }

    public function update(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('Update:ExpenseCategory');
    }

    /**
     * Never actually wired to a delete action anywhere — categories are
     * deactivated, never deleted, so historical Expense rows always keep
     * a valid reference. Policy method still exists for completeness.
     */
    public function delete(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return false;
    }
}
