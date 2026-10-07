<?php

namespace App\Filament\Resources\DeviceUsers;

use App\Filament\Resources\DeviceUsers\Pages\ManageDeviceUsers;
use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\User;
use App\Services\Attendance\DeviceLinkService;
use App\Services\Attendance\DeviceUserImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Badges on the fingerprint terminal, and who they belong to.
 *
 * The default view is the ones needing attention — a badge with no active
 * link is a person whose attendance is being recorded under no name at all,
 * which is the only state here that needs anybody to do something.
 */
class DeviceUserResource extends Resource
{
    protected static ?string $model = AttendanceDeviceUser::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Device Users';

    protected static ?string $modelLabel = 'Device User';

    protected static ?string $slug = 'device-users';

    /**
     * How many badges nobody has claimed. Shown on the nav item because it is
     * work waiting, not a statistic.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = AttendanceDeviceUser::unmatched()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return AttendanceDeviceUser::unmatched()->exists() ? 'warning' : null;
    }

    public static function form(Schema $schema): Schema
    {
        // Device users are created by the terminal and the importer, never by
        // hand on this screen.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('device_user_id')->label('Device ID')->searchable()->sortable(),
                TextColumn::make('device_name')
                    ->label('Name on device')
                    ->placeholder('— never named —')
                    ->searchable(),
                TextColumn::make('linked')
                    ->label('Linked to')
                    ->getStateUsing(fn (AttendanceDeviceUser $record) => $record->linkedUser()?->name)
                    ->placeholder('— not linked —')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
                TextColumn::make('suggestion')
                    ->label('Possible match')
                    ->getStateUsing(function (AttendanceDeviceUser $record) {
                        if ($record->linkedUser() !== null) {
                            return null;
                        }

                        $match = $record->suggestedMatch();

                        return $match ? $match['user']->name.' ('.round($match['score']).'%)' : null;
                    })
                    ->placeholder('—')
                    ->color('gray')
                    // A hint only. Nothing on this screen links automatically.
                    ->tooltip('A guess from the name on the device. Check it before linking.'),
                TextColumn::make('first_seen_at')->label('First seen')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('retired_at')->label('Retired')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('device_user_id')
            ->filters([
                TernaryFilter::make('unmatched')
                    ->label('Needs linking')
                    ->placeholder('All active badges')
                    ->trueLabel('Not linked yet')
                    ->falseLabel('Already linked')
                    ->queries(
                        true: fn (Builder $q) => $q->unmatched(),
                        false: fn (Builder $q) => $q->active()->whereHas('links', fn (Builder $l) => $l->active()),
                        blank: fn (Builder $q) => $q->active(),
                    )
                    ->default(true),
            ])
            ->recordActions([
                static::linkAction(),
                static::createStaffAndLinkAction(),
                static::endLinkAction(),
                static::voidLinkAction(),
            ])
            ->headerActions([
                static::importAction(),
            ])
            ->toolbarActions([]);
    }

    protected static function linkAction(): Action
    {
        return Action::make('link')
            ->label('Link to staff')
            ->icon('heroicon-o-link')
            ->visible(fn (AttendanceDeviceUser $record) => ! $record->isRetired() && $record->activeLink() === null)
            ->schema([
                Select::make('user_id')
                    ->label('Staff member')
                    ->options(fn () => User::whereNull('left_at')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                DatePicker::make('effective_from')
                    ->label('Linked from')
                    ->default(now())
                    ->required()
                    ->helperText('Punches from this date onward count as theirs.'),
            ])
            ->action(function (AttendanceDeviceUser $record, array $data) {
                static::guard(fn () => app(DeviceLinkService::class)->link(
                    $record,
                    User::findOrFail($data['user_id']),
                    \Carbon\CarbonImmutable::parse($data['effective_from']),
                    auth()->user(),
                ), 'Badge linked');
            });
    }

    protected static function createStaffAndLinkAction(): Action
    {
        return Action::make('createStaffAndLink')
            ->label('Create staff & link')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->visible(fn (AttendanceDeviceUser $record) => ! $record->isRetired() && $record->activeLink() === null)
            ->modalDescription('For somebody on the terminal who is not in Selum at all — cleaners, laundry. They get a staff record with no way into the app.')
            ->schema([
                TextInput::make('name')
                    ->label('Full name')
                    ->required()
                    ->helperText('The device name is only a starting point — put their real full name here.'),
                TextInput::make('job_title')->label('Job title')->placeholder('Cleaner, Laundry, Porter'),
                DatePicker::make('effective_from')->label('Linked from')->default(now())->required(),
            ])
            ->fillForm(fn (AttendanceDeviceUser $record) => ['name' => $record->device_name])
            ->action(function (AttendanceDeviceUser $record, array $data) {
                static::guard(fn () => app(DeviceLinkService::class)->createStaffAndLink(
                    $record,
                    $data['name'],
                    $data['job_title'] ?? null,
                    \Carbon\CarbonImmutable::parse($data['effective_from']),
                    auth()->user(),
                ), 'Staff member created and linked');
            });
    }

    protected static function endLinkAction(): Action
    {
        return Action::make('endLink')
            ->label('Person left')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('gray')
            ->visible(fn (AttendanceDeviceUser $record) => $record->activeLink() !== null)
            ->modalDescription('Their attendance so far stays theirs. The badge is retired and can never be linked to anybody again, because the terminal reuses IDs.')
            ->schema([
                DatePicker::make('last_day')->label('Last day')->default(now())->required(),
                TextInput::make('reason')->label('Reason')->placeholder('Resigned, contract ended'),
            ])
            ->action(function (AttendanceDeviceUser $record, array $data) {
                static::guard(fn () => app(DeviceLinkService::class)->end(
                    $record->activeLink(),
                    \Carbon\CarbonImmutable::parse($data['last_day']),
                    auth()->user(),
                    $data['reason'] ?? null,
                ), 'Link ended and badge retired');
            });
    }

    /**
     * Correcting a mistake, which is a different thing from somebody leaving:
     * this one moves the punches.
     */
    protected static function voidLinkAction(): Action
    {
        return Action::make('voidLink')
            ->label('Wrong person — correct it')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->visible(fn (AttendanceDeviceUser $record) => $record->activeLink() !== null)
            ->modalDescription('Use this only when the link was a mistake. Punches wrongly recorded against them will move to whoever you pick, or become unattributed if you pick nobody.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why is this wrong?')
                    ->required()
                    ->helperText('This is the only record of why the attendance history changed.'),
                Select::make('correct_user_id')
                    ->label('Actually belongs to')
                    ->options(fn () => User::whereNull('left_at')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Nobody — leave the punches unattributed'),
            ])
            ->action(function (AttendanceDeviceUser $record, array $data) {
                try {
                    $result = app(DeviceLinkService::class)->void(
                        $record->activeLink(),
                        $data['reason'],
                        auth()->user(),
                        filled($data['correct_user_id'] ?? null) ? User::find($data['correct_user_id']) : null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not correct the link')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                $body = $result['punches_reattributed'].' punch(es) moved.';

                // Surfaced, never reversed: money already taken off somebody
                // is a decision for a person, not a side effect of a fix.
                if ($result['affected_deductions']->isNotEmpty()) {
                    $dates = $result['affected_deductions']->pluck('date')->implode(', ');
                    $body .= ' Note: '.$result['affected_deductions']->count()
                        .' lateness fee(s) were charged to the previous person on '.$dates
                        .'. Those have NOT been reversed — handle them under Surcharges.';
                }

                Notification::make()
                    ->warning()
                    ->title('Link corrected')
                    ->body($body)
                    ->persistent()
                    ->send();
            });
    }

    protected static function importAction(): Action
    {
        return Action::make('import')
            ->label('Import device users')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalDescription('A CSV or XLSX exported from the terminal, with device_user_id and device_name columns. Matching is by device ID; retired badges are left alone.')
            ->schema([
                FileUpload::make('file')
                    ->label('File')
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data) {
                $file = $data['file'];
                $path = is_array($file) ? reset($file)->getRealPath() : $file->getRealPath();

                try {
                    $result = app(DeviceUserImporter::class)->import($path);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Import failed')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                $body = sprintf(
                    '%d new, %d renamed, %d unchanged, %d skipped (retired).',
                    $result['new'], $result['renamed'], $result['unchanged'], $result['skipped_retired'],
                );

                if ($result['errors'] !== []) {
                    $body .= ' '.count($result['errors']).' row(s) could not be read.';
                }

                Notification::make()->success()->title('Device users imported')->body($body)->persistent()->send();
            });
    }

    /**
     * Every write on this screen can be refused by the service for a reason
     * the admin needs to read — a retired badge, an already-linked person.
     * Surfacing it as a notification rather than letting it bubble is what
     * the no-bare-abort rule is about.
     */
    protected static function guard(callable $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Could not do that')
                ->body(collect($e->errors())->flatten()->implode(' '))->send();

            return;
        }

        Notification::make()->success()->title($successTitle)->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDeviceUsers::route('/'),
        ];
    }
}
