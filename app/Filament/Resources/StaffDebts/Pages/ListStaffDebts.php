<?php

namespace App\Filament\Resources\StaffDebts\Pages;

use App\Filament\Pages\StaffDebtReport;
use App\Filament\Resources\StaffDebts\StaffDebtResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffDebts extends ListRecords
{
    protected static string $resource = StaffDebtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add Manual Debt'),

            // Downloads live on the report page, not here: an export worth
            // taking to a payroll meeting needs the per-staff rollup and
            // the handover/manual split, which this list doesn't carry.
            // Hidden rather than shown-and-denied when the viewer hasn't
            // been granted that page.
            Action::make('downloadReport')
                ->label('Download Report')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => StaffDebtReport::getUrl())
                ->visible(fn (): bool => StaffDebtReport::canAccess()),
        ];
    }
}
