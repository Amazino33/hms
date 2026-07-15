<?php

namespace App\Filament\Ceo\Resources\HandoverCounts\Pages;

use App\Filament\Ceo\Resources\HandoverCounts\HandoverCountResource;
use Filament\Resources\Pages\ListRecords;

class ListHandoverCounts extends ListRecords
{
    protected static string $resource = HandoverCountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
