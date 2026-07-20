<?php

namespace App\Filament\Resources\ProductDeletionRequests\Pages;

use App\Filament\Resources\ProductDeletionRequests\ProductDeletionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListProductDeletionRequests extends ListRecords
{
    protected static string $resource = ProductDeletionRequestResource::class;
}
