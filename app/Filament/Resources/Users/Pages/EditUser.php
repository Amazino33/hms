<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Attendance\AttendanceExemptionService;
use App\Services\Attendance\AttendanceOnlyRoleService;
use App\Services\StaffAccessGuard;
use App\Services\StaffDeactivationService;
use App\Support\BusinessDay;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * On for this page so a refused role change rolls the whole save back —
     * the admin panel itself runs without transactions.
     */
    protected ?bool $hasDatabaseTransactions = true;

    private ?string $staffType = null;

    /** @var list<int> role ids as they were before this save */
    private array $rolesBefore = [];

    /**
     * No delete action, anywhere: staff are deactivated, never deleted
     * (User::booting(), UserPolicy).
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->exemptionAction(),
            $this->deactivateAction(),
            $this->reactivateAction(),
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

    /**
     * Deactivation is the only way someone leaves. The modal shows what they
     * still owe and any shift still open in their name — display only.
     */
    private function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn () => $this->record->left_at === null && StaffAccessGuard::canDeactivate(auth()->user()))
            ->authorize(fn () => StaffAccessGuard::canDeactivate(auth()->user()))
            ->modalHeading(fn () => "Deactivate {$this->record->name}")
            ->modalDescription('They keep their history — orders, shifts, debts and payslips stay in their name. From the end of their last working day they cannot sign in, use a PIN or start a shift.')
            ->modalSubmitActionLabel('Deactivate')
            ->schema([
                Placeholder::make('outstanding_debt')
                    ->label('Outstanding staff debt')
                    ->content(function () {
                        $owed = app(StaffDeactivationService::class)->outstandingDebt($this->record);

                        return $owed > 0 ? '₦'.number_format($owed, 2) : 'None';
                    }),
                Placeholder::make('open_shift')
                    ->label('Open shift')
                    ->content(function () {
                        $shift = app(StaffDeactivationService::class)->openShift($this->record);

                        return $shift
                            ? ucfirst($shift->type).' shift open since '.$shift->started_at->venueTime()->format('j M, g:i A').' — settle or close it separately.'
                            : 'None';
                    }),
                DatePicker::make('last_working_day')
                    ->label('Last working day')
                    ->helperText('Their access ends when this business day closes (9am the next morning).')
                    ->default(BusinessDay::today())
                    ->native(false)
                    // A calendar date, not an instant: without this the
                    // panel-wide Lagos timezone shifts it back to the
                    // previous day on the way out.
                    ->timezone('UTC')
                    ->required(),
                Select::make('reason')
                    ->options(User::LEFT_REASONS)
                    ->required()
                    ->live(),
                Textarea::make('note')
                    ->rows(3)
                    ->required(fn (Get $get) => $get('reason') === 'other'),
            ])
            ->action(function (array $data) {
                try {
                    $user = app(StaffDeactivationService::class)->deactivate(
                        $this->record,
                        \Carbon\CarbonImmutable::parse($data['last_working_day'])->toDateString(),
                        $data['reason'],
                        $data['note'] ?? null,
                        auth()->user(),
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not deactivate')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                $this->record = $user;

                Notification::make()->success()
                    ->title($user->isDeactivated() ? "{$user->name} is deactivated" : "{$user->name} is leaving")
                    ->body($user->isDeactivated()
                        ? 'Their PIN, trusted phones and sessions have been cleared.'
                        : 'They stay active until '.$user->left_at->venueTime()->format('j M, g:i A').'.')
                    ->send();
            });
    }

    private function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn () => $this->record->left_at !== null && StaffAccessGuard::canDeactivate(auth()->user()))
            ->authorize(fn () => StaffAccessGuard::canDeactivate(auth()->user()))
            ->requiresConfirmation()
            ->modalDescription('They can sign in again. Their kiosk PIN stays empty — they set a new one themselves.')
            ->action(function () {
                try {
                    $this->record = app(StaffDeactivationService::class)->reactivate($this->record, auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not reactivate')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                Notification::make()->success()->title("{$this->record->name} is active again")->send();
            });
    }

    protected function beforeValidate(): void
    {
        $this->rolesBefore = $this->currentRoleIds();
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
     *
     * Any role change — through the roles field or the staff-type toggle — is
     * refused here without assign_staff_roles, and the whole save is rolled
     * back. The form already shows both read-only; this is the server-side
     * half, for a request sent around the form.
     */
    protected function afterSave(): void
    {
        $canAssign = StaffAccessGuard::canAssignRoles(auth()->user());

        if (! $canAssign && $this->currentRoleIds() !== $this->rolesBefore) {
            $this->refuseRoleChange();
        }

        if ($this->staffType === null) {
            return;
        }

        $isAttendanceOnly = $this->record->hasRole(User::ATTENDANCE_ONLY_ROLE);

        try {
            if ($this->staffType === 'attendance_only' && ! $isAttendanceOnly) {
                AttendanceOnlyRoleService::makeAttendanceOnly($this->record, auth()->user());

                Notification::make()->success()->title('Converted to attendance-only')
                    ->body('Their roles, password and PIN have been cleared. Attendance is still tracked.')->send();

                return;
            }

            if ($this->staffType === 'app' && $isAttendanceOnly) {
                $roles = array_values(array_filter((array) ($this->data['roles'] ?? [])));

                AttendanceOnlyRoleService::makeAppUser(
                    $this->record,
                    \Spatie\Permission\Models\Role::whereIn('id', $roles)->pluck('name')->all(),
                    auth()->user(),
                );

                Notification::make()->success()->title('Converted to app user')
                    ->body('Set a password for them before they try to sign in.')->send();
            }
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($canAssign ? 'Pick at least one role' : 'Only the super admin can change staff roles')
                ->body(collect($e->errors())->flatten()->implode(' '))->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    /**
     * @throws Halt
     */
    private function refuseRoleChange(): never
    {
        Notification::make()->danger()->title('Only the super admin can change staff roles')
            ->body('Nothing was saved.')->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    /** @return list<int> */
    private function currentRoleIds(): array
    {
        return $this->record->roles()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
