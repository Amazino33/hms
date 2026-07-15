<?php

namespace App\Filament\Ceo\Resources\InventoryTransactions\Pages;

use App\Filament\Ceo\Resources\InventoryTransactions\InventoryTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListInventoryTransactions extends ListRecords
{
    protected static string $resource = InventoryTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
