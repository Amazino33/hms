<?php

namespace App\Filament\Ceo\Pages;

use App\Models\Category;
use App\Services\Ceo\StockAlertService;
use BackedEnum;
use Filament\Pages\Page;

class StockAlerts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $title = 'Stock Alerts';

    protected string $view = 'filament-ceo.pages.stock-alerts';

    public string $state = 'both';

    public ?string $category = null;

    public ?string $itemType = null;

    public ?string $location = null;

    public function rows()
    {
        return (new StockAlertService())->alerts(array_filter([
            'state' => $this->state,
            'category' => $this->category,
            'item_type' => $this->itemType,
            'location' => $this->location,
        ]));
    }

    public function categories()
    {
        return Category::orderBy('name')->pluck('name');
    }
}
