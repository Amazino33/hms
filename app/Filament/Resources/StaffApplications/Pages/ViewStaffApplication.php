<?php

namespace App\Filament\Resources\StaffApplications\Pages;

use App\Filament\Resources\StaffApplications\StaffApplicationResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Services\StaffApplicationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Approve or reject one application. Both actions are re-checked in
 * StaffApplicationService; hiding them here is only the screen's half.
 */
class ViewStaffApplication extends ViewRecord
{
    protected static string $resource = StaffApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
        ];
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn () => $this->record->isPending() && StaffApplicationService::canDecide(auth()->user()))
            ->authorize(fn () => StaffApplicationService::canDecide(auth()->user()))
            ->modalHeading(fn () => "Approve {$this->record->full_name}")
            ->modalDescription('This creates their staff account with a new permanent staff ID. They sign in with the email and password they chose, then set their own kiosk PIN. Salary is set separately.')
            ->modalSubmitActionLabel('Approve and create account')
            ->schema([
                ToggleButtons::make('staff_type')
                    ->label('Staff type')
                    ->options(['app' => 'App user', 'attendance_only' => 'Attendance only'])
                    ->default('app')
                    ->inline()
                    ->live()
                    ->required()
                    ->helperText(fn (Get $get) => $get('staff_type') === 'attendance_only'
                        ? 'No login of any kind; the password they chose is discarded. Attendance is still tracked.'
                        : 'Signs in with their own email and password.'),
                Select::make('roles')
                    ->label('Role')
                    ->multiple()
                    ->options(fn () => Role::where('name', '!=', User::ATTENDANCE_ONLY_ROLE)->orderBy('name')->pluck('name', 'name'))
                    ->visible(fn (Get $get) => $get('staff_type') === 'app')
                    ->required(fn (Get $get) => $get('staff_type') === 'app'),
                TextInput::make('job_title')
                    ->label('Job title (optional)')
                    ->placeholder('Waiter, Cleaner, Laundry')
                    ->maxLength(120),
            ])
            ->action(function (array $data) {
                try {
                    $user = app(StaffApplicationService::class)->approve(
                        $this->record,
                        auth()->user(),
                        $data['staff_type'],
                        (array) ($data['roles'] ?? []),
                        $data['job_title'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not approve')
                        ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();

                    return;
                }

                Notification::make()->success()
                    ->title("{$user->name} is now staff — {$user->staff_code}")
                    ->body('Their staff record is open below. Add their schedule there. Salary is set with the payroll update, not on this record.')
                    ->persistent()
                    ->send();

                $this->redirect(EditUser::getUrl(['record' => $user]));
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn () => $this->record->isPending() && StaffApplicationService::canDecide(auth()->user()))
            ->authorize(fn () => StaffApplicationService::canDecide(auth()->user()))
            ->modalHeading(fn () => "Reject {$this->record->full_name}")
            ->modalDescription('Their personal details, photo and documents are deleted. Only the name, dates and your reason are kept.')
            ->modalSubmitActionLabel('Reject')
            ->schema([
                Textarea::make('reason')->label('Reason')->rows(3)->required(),
            ])
            ->action(function (array $data) {
                try {
                    $this->record = app(StaffApplicationService::class)->reject($this->record, auth()->user(), $data['reason']);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not reject')
                        ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Application rejected')->send();
            });
    }
}
