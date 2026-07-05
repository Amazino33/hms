<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Services\StockAdjustmentService;
use Filament\Resources\Pages\CreateRecord;
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
        return (new StockAdjustmentService())->request($data, auth()->id());
    }
}
