<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;

/**
 * amount/expense_category_id/date_incurred are immutable once created —
 * enforced here, not just by omission from a Filament edit form, so
 * nothing else in the app can quietly mutate a posted expense either.
 * Only note may change after creation; a real correction voids the row
 * and a fresh one gets entered.
 */
class ExpenseService
{
    /**
     * @param array{amount: float, expense_category_id: int, date_incurred?: string, note?: string, receipt_photo?: string} $data
     */
    public function create(array $data, int $enteredByUserId): Expense
    {
        if ((float) ($data['amount'] ?? 0) <= 0) {
            throw new \Exception('Amount must be greater than zero.');
        }

        $category = ExpenseCategory::find($data['expense_category_id'] ?? null);

        if (! $category) {
            throw new \Exception('Choose a valid expense category.');
        }

        if (! $category->is_active) {
            throw new \Exception('This category has been deactivated — choose another.');
        }

        return Expense::create([
            'amount' => $data['amount'],
            'expense_category_id' => $category->id,
            'date_incurred' => $data['date_incurred'] ?? now()->toDateString(),
            'note' => $data['note'] ?? null,
            'receipt_photo' => $data['receipt_photo'] ?? null,
            'entered_by' => $enteredByUserId,
        ]);
    }

    public function updateNote(Expense $expense, ?string $note): Expense
    {
        $expense->update(['note' => $note]);

        return $expense->fresh();
    }

    public function void(Expense $expense, int $voidedByUserId, string $reason): Expense
    {
        if ($expense->isVoided()) {
            throw new \Exception('This expense has already been voided.');
        }

        if (trim($reason) === '') {
            throw new \Exception('A reason is required to void an expense.');
        }

        $expense->update([
            'voided_at' => now(),
            'voided_by' => $voidedByUserId,
            'void_reason' => $reason,
        ]);

        activity('expense')
            ->performedOn($expense)
            ->causedBy(\App\Models\User::find($voidedByUserId))
            ->log('Expense voided');

        return $expense->fresh();
    }
}
