<?php

namespace App\Filament\Ceo\Resources\Products\Pages;

use App\Filament\Ceo\Resources\Products\ProductResource;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
