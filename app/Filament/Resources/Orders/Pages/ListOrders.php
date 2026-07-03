<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    // Orders are created exclusively through the POS checkout flow (see
    // OrderSplitter), which sets order_number/table_id/user_id correctly.
    // No manual "New Order" action is exposed here.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
