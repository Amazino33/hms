<?php

namespace App\Filament\Ceo\Resources\OwnerTakeNotes\Pages;

use App\Filament\Ceo\Resources\OwnerTakeNotes\OwnerTakeNoteResource;
use Filament\Resources\Pages\ListRecords;

class ListOwnerTakeNotes extends ListRecords
{
    protected static string $resource = OwnerTakeNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
