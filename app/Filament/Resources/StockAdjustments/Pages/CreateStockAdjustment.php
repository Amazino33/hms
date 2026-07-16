<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Services\StockAdjustmentService;
use App\Services\UserFeedback;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateStockAdjustment extends CreateRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    /**
     * Route creation through StockAdjustmentService so this can never be
     * anything but a pending request — status, requested_by, and the
     * approval fields are never settable from the form itself.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return (new StockAdjustmentService())->request($data, auth()->id());
        } catch (\Throwable $e) {
            report($e);
            UserFeedback::blocked('Could not submit adjustment request', 'Check the product/ingredient and quantity, then try again.');

            throw new Halt();
        }
    }
}
