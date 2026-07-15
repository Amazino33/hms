<?php

namespace App\Filament\Ceo\Resources\Orders\Pages;

use App\Filament\Ceo\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
