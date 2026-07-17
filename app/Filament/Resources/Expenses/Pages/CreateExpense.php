<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\ExpenseService;
use App\Services\UserFeedback;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Routed through ExpenseService so entered_by is always the acting user
 * (never form-settable) and the active-category / positive-amount checks
 * apply here exactly as they would anywhere else this service is called.
 */
class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ExpenseService::class)->create($data, auth()->id());
        } catch (\Throwable $e) {
            report($e);
            UserFeedback::blocked('Could not record expense', $e->getMessage());

            throw new Halt();
        }
    }
}
