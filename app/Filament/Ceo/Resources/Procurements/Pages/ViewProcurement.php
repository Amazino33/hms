<?php

namespace App\Filament\Ceo\Resources\Procurements\Pages;

use App\Filament\Ceo\Resources\Procurements\ProcurementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewProcurement extends ViewRecord
{
    protected static string $resource = ProcurementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Procurements')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => ProcurementResource::getUrl('index')),
        ];
    }
}
