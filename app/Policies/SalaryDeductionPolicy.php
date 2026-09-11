<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalaryDeduction;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SalaryDeductionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SalaryDeduction');
    }

    public function view(AuthUser $authUser, SalaryDeduction $salaryDeduction): bool
    {
        return $authUser->can('View:SalaryDeduction');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SalaryDeduction');
    }

    public function update(AuthUser $authUser, SalaryDeduction $salaryDeduction): bool
    {
        return $authUser->can('Update:SalaryDeduction');
    }

    public function delete(AuthUser $authUser, SalaryDeduction $salaryDeduction): bool
    {
        return $authUser->can('Delete:SalaryDeduction');
    }

    public function restore(AuthUser $authUser, SalaryDeduction $salaryDeduction): bool
    {
        return $authUser->can('Restore:SalaryDeduction');
    }

    public function forceDelete(AuthUser $authUser, SalaryDeduction $salaryDeduction): bool
    {
        return $authUser->can('ForceDelete:SalaryDeduction');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SalaryDeduction');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SalaryDeduction');
    }

    public function replicate(AuthUser $authUser, SalaryDeduction $salaryDeduction): bool
    {
        return $authUser->can('Replicate:SalaryDeduction');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SalaryDeduction');
    }
}
