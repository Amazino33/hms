<?php

namespace App\Filament\Resources\ShiftTemplates;

use App\Filament\Resources\ShiftTemplates\Pages\ManageShiftTemplates;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Services\Attendance\ShiftTemplateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Shift patterns staff can be scheduled on.
 *
 * Gated by a real Shield policy (AttendanceShiftTemplatePolicy) — a Resource
 * with no generated policy is open to every authenticated panel user, and
 * these define what counts as being late.
 */
class ShiftTemplateResource extends Resource
{
    protected static ?string $model = AttendanceShiftTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Shift Templates';

    protected static ?string $modelLabel = 'Shift Template';

    protected static ?string $slug = 'shift-templates';

    /**
     * Chips and steppers rather than dropdowns: the admin building a rota is
     * reading days of the week off a paper roster, and a seven-option select
     * makes that a seven-click job.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('What staff call this shift — "Day shift", "Night bar".'),

            TimePicker::make('start_time')
                ->label('Starts at')
                ->seconds(false)
                ->required()
                ->disabled(fn (?AttendanceShiftTemplate $record) => $record?->isLocked() ?? false),

            TextInput::make('duration_hours')
                ->label('Length (hours)')
                ->numeric()
                ->step(0.5)
                ->minValue(0.5)
                ->maxValue(24)
                ->required()
                ->disabled(fn (?AttendanceShiftTemplate $record) => $record?->isLocked() ?? false)
                ->helperText('24 for a full-day bar shift. The end time is worked out from this, so a shift crossing midnight needs no special handling.')
                ->afterStateHydrated(fn ($component, ?AttendanceShiftTemplate $record) => $component->state($record?->durationHours()))
                ->dehydrated(false),

            ToggleButtons::make('pattern_type')
                ->label('Pattern')
                ->options(['weekly' => 'Weekly', 'rotation' => 'Rotation'])
                ->icons(['weekly' => 'heroicon-o-calendar', 'rotation' => 'heroicon-o-arrow-path'])
                ->inline()
                ->default('weekly')
                ->required()
                ->live()
                ->disabled(fn (?AttendanceShiftTemplate $record) => $record?->isLocked() ?? false),

            ToggleButtons::make('weekly_days')
                ->label('Days worked')
                ->multiple()
                ->inline()
                ->options([
                    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
                    5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
                ])
                ->visible(fn (Get $get) => $get('pattern_type') === 'weekly')
                ->requiredIf('pattern_type', 'weekly')
                ->disabled(fn (?AttendanceShiftTemplate $record) => $record?->isLocked() ?? false),

            TextInput::make('rotation_on_days')
                ->label('Days on')
                ->numeric()
                ->minValue(1)
                ->maxValue(30)
                ->default(1)
                ->visible(fn (Get $get) => $get('pattern_type') === 'rotation')
                ->requiredIf('pattern_type', 'rotation')
                ->disabled(fn (?AttendanceShiftTemplate $record) => $record?->isLocked() ?? false),

            TextInput::make('rotation_off_days')
                ->label('Days off')
                ->numeric()
                ->minValue(0)
                ->maxValue(30)
                ->default(1)
                ->visible(fn (Get $get) => $get('pattern_type') === 'rotation')
                ->requiredIf('pattern_type', 'rotation')
                ->disabled(fn (?AttendanceShiftTemplate $record) => $record?->isLocked() ?? false),

            Toggle::make('is_handover')
                ->label('Handover shift')
                ->helperText('Late arrival on this shift is fined as late relief — somebody is waiting to be relieved and cannot leave.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('hours')
                    ->label('Hours')
                    ->getStateUsing(fn (AttendanceShiftTemplate $record) => $record->describeHours()),
                TextColumn::make('pattern')
                    ->label('Pattern')
                    ->getStateUsing(fn (AttendanceShiftTemplate $record) => static::describePattern($record))
                    ->wrap(),
                IconColumn::make('is_handover')->label('Handover')->boolean(),
                TextColumn::make('assignments_count')
                    ->label('Staff on it')
                    ->counts('assignments')
                    ->badge(),
                TextColumn::make('retired_at')
                    ->label('Retired')
                    ->dateTime()
                    ->placeholder('Active')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                static::duplicateAndReplaceAction(),
                static::retireAction(),
            ])
            ->toolbarActions([])
            ->headerActions([
                CreateAction::make(),
            ]);
    }

    /**
     * The only way to change a locked template's timing.
     *
     * Editing in place would retroactively move the line between late and on
     * time for every day already worked under it, so the change is dated: the
     * old template is retired and everyone on it moves to the new one from a
     * chosen day forward.
     */
    protected static function duplicateAndReplaceAction(): Action
    {
        return Action::make('duplicateAndReplace')
            ->label('Duplicate & replace')
            ->icon('heroicon-o-document-duplicate')
            ->color('warning')
            ->visible(fn (AttendanceShiftTemplate $record) => $record->isLocked() && ! $record->isRetired())
            ->modalDescription('Creates a new template with these times and moves everyone from the date you choose. History before that date keeps the old times.')
            ->schema([
                TimePicker::make('start_time')->label('New start time')->seconds(false)->required(),
                TextInput::make('duration_hours')->label('New length (hours)')->numeric()->step(0.5)->minValue(0.5)->maxValue(24)->required(),
                DatePicker::make('effective_from')->label('Change takes effect')->required()->default(now()),
            ])
            ->fillForm(fn (AttendanceShiftTemplate $record) => [
                'start_time' => $record->startTimeString(),
                'duration_hours' => $record->durationHours(),
            ])
            ->action(function (AttendanceShiftTemplate $record, array $data) {
                try {
                    app(ShiftTemplateService::class)->duplicateAndReplace($record, [
                        'start_time' => $data['start_time'],
                        'duration_minutes' => (int) round(((float) $data['duration_hours']) * 60),
                        'pattern_type' => $record->pattern_type,
                        'weekly_days' => $record->weekly_days,
                        'rotation_on_days' => $record->rotation_on_days,
                        'rotation_off_days' => $record->rotation_off_days,
                    ], \Carbon\CarbonImmutable::parse($data['effective_from']), auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not replace the template')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                Notification::make()->success()->title('Template replaced')
                    ->body('Everyone on the old template moves across on the chosen date.')->send();
            });
    }

    protected static function retireAction(): Action
    {
        return Action::make('retire')
            ->label('Retire')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Retiring hides this template from new assignments. Existing history keeps it, and nothing is deleted.')
            ->visible(fn (AttendanceShiftTemplate $record) => ! $record->isRetired())
            ->action(function (AttendanceShiftTemplate $record) {
                app(ShiftTemplateService::class)->retire($record, auth()->user());

                Notification::make()->success()->title('Template retired')->send();
            });
    }

    public static function describePattern(AttendanceShiftTemplate $template): string
    {
        if ($template->isRotation()) {
            return $template->rotation_on_days.' on / '.$template->rotation_off_days.' off';
        }

        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = array_map('intval', $template->weekly_days ?? []);
        sort($days);

        if (count($days) === 7) {
            return 'Every day';
        }

        return implode(', ', array_map(fn ($d) => $names[$d] ?? $d, $days)) ?: 'No days set';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShiftTemplates::route('/'),
        ];
    }
}
