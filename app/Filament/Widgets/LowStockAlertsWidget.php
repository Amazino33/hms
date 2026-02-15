<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Services\InventoryService;

class LowStockAlertsWidget extends Widget
{
    protected string $view = 'filament.widgets.low-stock-alerts-widget';

    protected int | string | array $columnSpan = 'full';

    // Deferred + collapsed-by-default behavior
    public bool $ready = false;
    public bool $expanded = false;
    public int $visibleLimit = 6; // show only top 6 by default

    public function load(): void
    {
        $this->ready = true;
    }

    public function toggleExpanded(): void
    {
        $this->expanded = ! $this->expanded;
    }

    public function visibleLowStockAlerts()
    {
        $alerts = collect(InventoryService::getLowStockAlerts(10))
            ->sortBy(fn ($a) => $a['ingredient']->quantity);

        return $this->expanded ? $alerts : $alerts->take($this->visibleLimit);
    }

    public function totalLowStockCount(): int
    {
        return count(InventoryService::getLowStockAlerts(10));
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        if (! $this->ready) {
            return view('filament.widgets._deferred-placeholder');
        }

        return parent::render();
    }

    // Backwards-compatible: full sorted list (used by tests or other callers)
    public function getLowStockAlerts()
    {
        return collect(InventoryService::getLowStockAlerts(10))->sortBy(fn($a) => $a['ingredient']->quantity)->values()->all();
    }
}