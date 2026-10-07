<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\ShiftTemplates\ShiftTemplateResource;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Services\Attendance\HandoverCoverageChecker;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftScheduleResolver;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * What this person is scheduled to work, and what they have been.
 *
 * The history is the point. A fine three months from now has to be defensible
 * against the schedule that was in force on the day, so every change closes
 * the previous row and opens a new one rather than editing in place.
 */
class ScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'attendanceShiftAssignments';

    protected static ?string $title = 'Schedule';

    protected static \BackedEnum|string|null $icon = 'heroicon-o-calendar-days';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Schedule')
            ->description(fn () => $this->scheduleSummary())
            ->columns([
                TextColumn::make('template.name')->label('Pattern')->description(
                    fn (AttendanceShiftAssignment $record) => $record->template
                        ? $record->template->describeHours().' · '.ShiftTemplateResource::describePattern($record->template)
                        : null,
                ),
                TextColumn::make('days')
                    ->label('Days')
                    ->getStateUsing(fn (AttendanceShiftAssignment $record) => $this->describeDays($record))
                    ->wrap(),
                TextColumn::make('effective_from')->label('From')->date()->sortable(),
                TextColumn::make('effective_to')->label('Until')->date()->placeholder('Current'),
                TextColumn::make('reason')->label('Reason')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('creator.name')->label('Set by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                $this->changeScheduleAction(),
                $this->previewAction(),
            ])
            // Append-only: no edit, no delete. Changing a schedule is a new
            // row, which the header action does.
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * The line under the heading: either the current pattern, or the warning
     * that this person is invisible to attendance entirely.
     */
    private function scheduleSummary(): string
    {
        $user = $this->getOwnerRecord();

        if ($user->attendance_exempt) {
            return 'Exempt from attendance — never scheduled, never fined.';
        }

        $current = app(ShiftAssignmentService::class)->currentFor($user);

        if ($current === null) {
            return 'No schedule — this person will not be checked for attendance.';
        }

        return 'Currently on '.$current->template?->name
            .' ('.$current->template?->describeHours().', '.$this->describeDays($current).')'
            .' since '.$current->effective_from->format('j M Y').'.';
    }

    private function describeDays(AttendanceShiftAssignment $assignment): string
    {
        $template = $assignment->template;

        if ($template === null) {
            return '—';
        }

        if ($template->isRotation()) {
            return $template->rotation_on_days.' on / '.$template->rotation_off_days.' off from '
                .($assignment->rotation_anchor_date?->format('j M Y') ?? 'no anchor');
        }

        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = array_map('intval', $assignment->weekly_days_override ?? $template->weekly_days ?? []);
        sort($days);

        if (count($days) === 7) {
            return 'Every day';
        }

        return implode(', ', array_map(fn ($d) => $names[$d] ?? $d, $days)) ?: 'No days';
    }

    private function changeScheduleAction(): Action
    {
        return Action::make('changeSchedule')
            ->label('Change schedule')
            ->icon('heroicon-o-arrow-path')
            ->modalDescription('The current schedule is closed the day before this one starts. Nothing already recorded changes.')
            ->schema([
                ToggleButtons::make('attendance_shift_template_id')
                    ->label('Pattern')
                    ->options(fn () => AttendanceShiftTemplate::active()->orderBy('name')->pluck('name', 'id'))
                    ->inline()
                    ->required()
                    ->live(),

                DatePicker::make('effective_from')
                    ->label('Starts')
                    ->default(now())
                    ->required(),

                ToggleButtons::make('weekly_days_override')
                    ->label('Days worked')
                    ->multiple()
                    ->inline()
                    ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])
                    ->helperText('Pre-filled from the pattern. Narrow it if this person works fewer days than the template.')
                    ->visible(fn (Get $get) => $this->templateFrom($get)?->isWeekly() ?? false)
                    ->default(fn (Get $get) => $this->templateFrom($get)?->weekly_days ?? []),

                DatePicker::make('rotation_anchor_date')
                    ->label('A day they are working')
                    ->helperText('The rotation is counted from this date. Get it wrong by one day and the whole rota shifts.')
                    ->visible(fn (Get $get) => $this->templateFrom($get)?->isRotation() ?? false)
                    ->requiredIf('attendance_shift_template_id', fn (Get $get) => $this->templateFrom($get)?->isRotation()),

                TextInput::make('reason')
                    ->label('Reason')
                    ->placeholder('Moved to nights, covering for X'),
            ])
            ->action(function (array $data) {
                $template = AttendanceShiftTemplate::find($data['attendance_shift_template_id']);

                try {
                    app(ShiftAssignmentService::class)->assign(
                        $this->getOwnerRecord(),
                        $template,
                        CarbonImmutable::parse($data['effective_from']),
                        $data['weekly_days_override'] ?? null,
                        filled($data['rotation_anchor_date'] ?? null)
                            ? CarbonImmutable::parse($data['rotation_anchor_date'])
                            : null,
                        $data['reason'] ?? null,
                        auth()->user(),
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Could not change the schedule')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                Notification::make()->success()->title('Schedule updated')->send();

                $this->warnAboutCoverage($template);
            });
    }

    /**
     * On a handover rota a gap means somebody cannot go home and a double-up
     * means somebody is sent away — and neither is visible from this one
     * person's screen, which is exactly why it is checked here.
     */
    private function warnAboutCoverage(AttendanceShiftTemplate $template): void
    {
        if (! $template->is_handover || ! $template->isRotation()) {
            return;
        }

        $checker = app(HandoverCoverageChecker::class);
        $result = $checker->check($template, 14);

        if (! $checker->hasProblems($result)) {
            return;
        }

        $lines = [];

        if ($result['gaps'] !== []) {
            $lines[] = 'Nobody is on '.$template->name.' on: '.implode(', ', $result['gaps']).'.';
        }

        foreach ($result['overlaps'] as $overlap) {
            $lines[] = $overlap['date'].': '.implode(' and ', $overlap['names']).' are both on.';
        }

        Notification::make()
            ->warning()
            ->title('Check the next two weeks of cover')
            ->body(implode(' ', $lines))
            ->persistent()
            ->send();
    }

    /**
     * The next seven shifts, which is how an admin actually confirms a
     * rotation is anchored on the right day — the anchor date alone tells
     * nobody anything.
     */
    private function previewAction(): Action
    {
        return Action::make('previewShifts')
            ->label('Next 7 shifts')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function () {
                $shifts = app(ShiftScheduleResolver::class)->nextShifts($this->getOwnerRecord(), 7);

                return view('filament.components.shift-preview', [
                    'shifts' => $shifts,
                    'timezone' => VenueTime::TIMEZONE,
                ]);
            });
    }

    private function templateFrom(Get $get): ?AttendanceShiftTemplate
    {
        $id = $get('attendance_shift_template_id');

        return $id ? AttendanceShiftTemplate::find($id) : null;
    }
}
