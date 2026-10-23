<?php

namespace App\Filament\Resources\StaffApplications\Pages;

use App\Filament\Resources\StaffApplications\StaffApplicationResource;
use App\Services\StaffApplicationService;
use App\Services\StaffApplicationSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * The open/close switch for the public /join form lives here, next to the
 * applications it produces, together with the link to send to applicants.
 */
class ListStaffApplications extends ListRecords
{
    protected static string $resource = StaffApplicationResource::class;

    public function getSubheading(): ?string
    {
        return StaffApplicationSettings::isOpen()
            ? 'Applications are OPEN. Send this link to applicants: '.route('staff.join')
            : 'Applications are CLOSED. The link '.route('staff.join').' shows a "closed" message until you open them.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openApplications')
                ->label('Open applications')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn () => ! StaffApplicationSettings::isOpen() && StaffApplicationService::canDecide(auth()->user()))
                ->authorize(fn () => StaffApplicationService::canDecide(auth()->user()))
                ->requiresConfirmation()
                ->modalDescription('Anyone with the link can then send an application. Each one waits here for you; nobody gets a login until you approve them.')
                ->action(function () {
                    StaffApplicationSettings::set(true, auth()->user());
                    Notification::make()->success()->title('Applications are open')->body(route('staff.join'))->send();
                }),

            Action::make('closeApplications')
                ->label('Close applications')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->visible(fn () => StaffApplicationSettings::isOpen() && StaffApplicationService::canDecide(auth()->user()))
                ->authorize(fn () => StaffApplicationService::canDecide(auth()->user()))
                ->requiresConfirmation()
                ->modalDescription('The link will show "Applications are currently closed". Applications already sent stay here.')
                ->action(function () {
                    StaffApplicationSettings::set(false, auth()->user());
                    Notification::make()->success()->title('Applications are closed')->send();
                }),
        ];
    }
}
