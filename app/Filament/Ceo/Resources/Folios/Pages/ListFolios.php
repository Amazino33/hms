<?php

namespace App\Filament\Ceo\Resources\Folios\Pages;

use App\Filament\Ceo\Resources\Folios\FolioResource;
use Filament\Resources\Pages\ListRecords;

class ListFolios extends ListRecords
{
    protected static string $resource = FolioResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
