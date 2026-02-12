<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Services\InventoryService;

class LowStockAlertsWidget extends Widget
{
    protected string $view = 'filament.widgets.low-stock-alerts-widget';

    protected int | string | array $columnSpan = 'full';

    public function getLowStockAlerts()
    {
        return InventoryService::getLowStockAlerts(10); // Alert when stock <= 10
    }
}