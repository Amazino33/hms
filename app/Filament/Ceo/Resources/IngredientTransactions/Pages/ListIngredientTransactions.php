<?php

namespace App\Filament\Ceo\Resources\IngredientTransactions\Pages;

use App\Filament\Ceo\Resources\IngredientTransactions\IngredientTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListIngredientTransactions extends ListRecords
{
    protected static string $resource = IngredientTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
