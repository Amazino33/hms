<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class PwaInstallWidget extends Widget
{
    protected string $view = 'filament.widgets.pwa-install-widget';
    
    // Put it at the very top of the dashboard
    protected static ?int $sort = -1; 
    
    // Make it span the full width
    protected int | string | array $columnSpan = 'full';
}