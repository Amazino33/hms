<?php

namespace App\Filament\Resources\ChipGroups\Pages;

use App\Filament\Resources\ChipGroups\ChipGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChipGroups extends ListRecords
{
    protected static string $resource = ChipGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
