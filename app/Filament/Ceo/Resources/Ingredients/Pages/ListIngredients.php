<?php

namespace App\Filament\Ceo\Resources\Ingredients\Pages;

use App\Filament\Ceo\Resources\Ingredients\IngredientResource;
use Filament\Resources\Pages\ListRecords;

class ListIngredients extends ListRecords
{
    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
