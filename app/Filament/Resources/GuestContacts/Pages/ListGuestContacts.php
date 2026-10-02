<?php

namespace App\Filament\Resources\GuestContacts\Pages;

use App\Filament\Resources\GuestContacts\GuestContactResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListGuestContacts extends ListRecords
{
    protected static string $resource = GuestContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // D27: the marketing list — opted-in contacts only.
            Action::make('exportOptedIn')
                ->label('Export opted-in (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => response()->streamDownload(function () {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['phone', 'guest_name', 'opted_in_at']);

                    foreach (GuestContactResource::exportRows() as $row) {
                        fputcsv($out, $row);
                    }

                    fclose($out);
                }, 'guest-contacts-opted-in-'.now()->venueTime()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv'])),
        ];
    }
}
