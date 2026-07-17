<?php

namespace App\Filament\Resources\ExpenseCategories\Pages;

use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Deliberately no DeleteAction here — categories are never deleted, only
 * deactivated via the is_active toggle on this same form.
 */
class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
