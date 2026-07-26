<?php

namespace App\Filament\Ceo\Resources\MenuItems\Pages;

use App\Filament\Ceo\Resources\MenuItems\MenuItemResource;
use Filament\Resources\Pages\ListRecords;

class ListMenuItems extends ListRecords
{
    protected static string $resource = MenuItemResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
