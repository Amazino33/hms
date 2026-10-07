<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Attendance\AttendanceExemptionService;
use App\Services\Attendance\AttendanceOnlyRoleService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    private ?string $staffType = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->exemptionAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Only a super admin may exempt somebody from attendance, because an
     * exempt user is never resolved into shifts and so can never be late,
     * absent or fined. Every change is logged with who made it.
     */
    private function exemptionAction(): Action
    {
        return Action::make('attendanceExemption')
            ->label(fn () => $this->record->attendance_exempt
                ? 'Remove attendance exemption'
                : 'Exempt from attendance')
            ->icon('heroicon-o-shield-check')
            ->color(fn () => $this->record->attendance_exempt ? 'warning' : 'gray')
            ->visible(fn () => AttendanceExemptionService::canChange(auth()->user()))
            ->requiresConfirmation()
            ->modalDescription(fn () => $this->record->attendance_exempt
                ? 'They will be checked for attendance again, and will appear in the no-schedule warning until they have one.'
                : 'They will never be checked for attendance, never fined, and never listed as unscheduled. For the owner and the CEO.')
            ->action(function () {
                try {
                    AttendanceExemptionService::set(
                        $this->record,
                        ! $this->record->attendance_exempt,
                        auth()->user(),
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not change exemption')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                Notification::make()->success()->title('Attendance exemption updated')->send();
            });
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->staffType = $this->data['staff_type'] ?? null;

        unset($data['staff_type']);

        return $data;
    }

    /**
     * Converting runs after the ordinary save and always on the same record —
     * a cleaner later made a waiter keeps their id, their attendance history
     * and their device link. A second account for the same person would split
     * all three.
     */
    protected function afterSave(): void
    {
        if ($this->staffType === null) {
            return;
        }

        $isAttendanceOnly = $this->record->hasRole(User::ATTENDANCE_ONLY_ROLE);

        if ($this->staffType === 'attendance_only' && ! $isAttendanceOnly) {
            AttendanceOnlyRoleService::makeAttendanceOnly($this->record, auth()->user());

            Notification::make()->success()->title('Converted to attendance-only')
                ->body('Their roles, password and PIN have been cleared. Attendance is still tracked.')->send();

            return;
        }

        if ($this->staffType === 'app' && $isAttendanceOnly) {
            $roles = array_values(array_filter((array) ($this->data['roles'] ?? [])));

            try {
                AttendanceOnlyRoleService::makeAppUser(
                    $this->record,
                    \Spatie\Permission\Models\Role::whereIn('id', $roles)->pluck('name')->all(),
                    auth()->user(),
                );
            } catch (ValidationException $e) {
                Notification::make()->danger()->title('Pick at least one role')
                    ->body(collect($e->errors())->flatten()->implode(' '))->send();

                return;
            }

            Notification::make()->success()->title('Converted to app user')
                ->body('Set a password for them before they try to sign in.')->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
