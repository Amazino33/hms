<?php

namespace App\Filament\Pages;

use App\Models\Attendance\AttendanceReviewItem;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Services\Attendance\ShiftReevaluationService;
use App\Support\VenueTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * One day's attendance, as judged.
 *
 * Defaults to yesterday rather than today, because today's shifts have mostly
 * not finished and an unfinalised row says nothing useful — the board exists
 * to be looked at in the morning, about the night before.
 */
class AttendanceBoard extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Attendance Board';

    protected static ?string $title = 'Attendance Board';

    protected static ?string $slug = 'attendance-board';

    protected string $view = 'filament.pages.attendance-board';

    public ?string $date = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:AttendanceShiftTemplate') ?? false;
    }

    public function mount(): void
    {
        $this->date = CarbonImmutable::now(VenueTime::TIMEZONE)->subDay()->toDateString();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')
                ->label('Shift date')
                ->live()
                ->afterStateUpdated(fn () => $this->resetTable()),
        ]);
    }

    /**
     * @return array<string, array{label: string, value: int, colour: string}>
     */
    public function getTiles(): array
    {
        $records = AttendanceShiftRecord::current()->forDate($this->shiftDate())->get();

        $count = fn (array $outcomes) => $records->whereIn('outcome', $outcomes)->count();

        return [
            'scheduled' => ['label' => 'Scheduled', 'value' => $records->count(), 'colour' => 'gray'],
            'present' => ['label' => 'Present', 'value' => $count(['present']), 'colour' => 'success'],
            'late' => ['label' => 'Late', 'value' => $count(['late', 'late_relief', 'late_and_early_leave', 'late_no_clockout']), 'colour' => 'warning'],
            'early_leave' => ['label' => 'Left early', 'value' => $count(['early_leave', 'late_and_early_leave']), 'colour' => 'warning'],
            'no_clockout' => ['label' => 'No clock-out', 'value' => $count(['no_clockout', 'late_no_clockout']), 'colour' => 'danger'],
            'absent' => ['label' => 'Absent', 'value' => $count(['absent']), 'colour' => 'danger'],
            'unlinked' => ['label' => 'Not linked', 'value' => $count(['unlinked']), 'colour' => 'gray'],
            'review' => [
                'label' => 'Review items',
                'value' => AttendanceReviewItem::open()->whereDate('shift_date', $this->shiftDate())->count(),
                'colour' => 'info',
            ],
        ];
    }

    /**
     * Shifts due on this date that have not been judged yet — shown as
     * "pending" rather than omitted, so an empty board is distinguishable
     * from a day nobody worked.
     */
    public function pendingCount(): int
    {
        $date = CarbonImmutable::parse($this->shiftDate(), VenueTime::TIMEZONE);

        if ($date->greaterThanOrEqualTo(CarbonImmutable::now(VenueTime::TIMEZONE)->startOfDay())) {
            return app(\App\Services\Attendance\ShiftScheduleResolver::class) !== null
                ? $this->expectedToday($date)
                : 0;
        }

        return 0;
    }

    private function expectedToday(CarbonImmutable $date): int
    {
        $resolver = app(\App\Services\Attendance\ShiftScheduleResolver::class);
        $judged = AttendanceShiftRecord::current()->forDate($date->toDateString())->count();

        $expected = 0;

        foreach (User::where('attendance_exempt', false)->get() as $user) {
            $expected += $resolver->expectedShifts($user, $date, $date)->count();
        }

        return max(0, $expected - $judged);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AttendanceShiftRecord::current()
                ->with(['user', 'template', 'fines'])
                ->forDate($this->shiftDate()))
            ->columns([
                TextColumn::make('user.name')->label('Staff')->searchable()->sortable(),
                TextColumn::make('template.name')->label('Shift')->placeholder('—'),
                TextColumn::make('scheduled')
                    ->label('Scheduled')
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->scheduled_start_at->timezone(VenueTime::TIMEZONE)->format('H:i')
                        .' → '.$r->scheduled_end_at->timezone(VenueTime::TIMEZONE)->format('H:i')
                        .($r->scheduled_start_at->timezone(VenueTime::TIMEZONE)->toDateString()
                            !== $r->scheduled_end_at->timezone(VenueTime::TIMEZONE)->toDateString() ? ' (+1d)' : '')),
                TextColumn::make('times')
                    ->label('In / Out')
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => ($r->clock_in_at?->timezone(VenueTime::TIMEZONE)->format('H:i') ?? '—')
                        .' / '.($r->clock_out_at?->timezone(VenueTime::TIMEZONE)->format('H:i') ?? '—')),
                TextColumn::make('variance')
                    ->label('Late / early')
                    ->getStateUsing(function (AttendanceShiftRecord $r) {
                        $parts = [];
                        if ($r->late_minutes > 0) {
                            $parts[] = $r->late_minutes.'m late';
                        }
                        if ($r->early_leave_minutes > 0) {
                            $parts[] = $r->early_leave_minutes.'m early';
                        }

                        return $parts === [] ? '—' : implode(', ', $parts);
                    }),
                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->badge()
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->outcomeLabel())
                    ->color(fn (AttendanceShiftRecord $r) => $r->outcomeColour()),
                TextColumn::make('fine_total')
                    ->label('Fines')
                    // Fines and withheld pay are different things and must
                    // never be summed into one figure shown to somebody.
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->fines->whereNull('voided_at')->where('kind', 'fine')->sum('amount'))
                    ->money('ngn')
                    ->placeholder('—'),
                TextColumn::make('pay_deduction_total')
                    ->label('Pay withheld')
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->fines->whereNull('voided_at')->where('kind', 'pay_deduction')->sum('amount'))
                    ->money('ngn')
                    ->placeholder('—'),
                TextColumn::make('shadow')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->fines->whereNull('voided_at')->where('is_shadow', false)->isNotEmpty() ? 'Live' : 'Shadow')
                    ->color(fn (string $state) => $state === 'Live' ? 'danger' : 'gray'),
                TextColumn::make('flags')
                    ->label('Flags')
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => implode(', ', array_map(
                        fn ($f) => str_replace('_', ' ', $f),
                        $r->review_flags ?? [],
                    )))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('outcome', 'desc')
            ->filters([
                SelectFilter::make('outcome')
                    ->options(array_combine(
                        AttendanceShiftRecord::OUTCOMES,
                        array_map(fn ($o) => str_replace('_', ' ', ucfirst($o)), AttendanceShiftRecord::OUTCOMES),
                    ))
                    ->multiple(),
                SelectFilter::make('template_id')
                    ->label('Shift template')
                    ->options(fn () => AttendanceShiftTemplate::orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('user_id')
                    ->label('Staff')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                TernaryFilter::make('flagged')
                    ->label('Has review flags')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('review_flags')->where('review_flags', '!=', '[]'),
                        false: fn (Builder $q) => $q->where(fn (Builder $i) => $i->whereNull('review_flags')->orWhere('review_flags', '[]')),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->recordActions([$this->detailAction()])
            ->toolbarActions([])
            ->headerActions([$this->reevaluateAction()])
            ->emptyStateHeading('Nothing judged for this date yet')
            ->emptyStateDescription('Either nobody was scheduled, or the shifts have not finished and been finalised.');
    }

    private function detailAction(): Action
    {
        return Action::make('detail')
            ->label('Detail')
            ->icon('heroicon-o-magnifying-glass')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->slideOver()
            ->modalHeading(fn (AttendanceShiftRecord $record) => $record->user?->name.' — '.$record->shift_date->format('j M Y'))
            ->modalContent(fn (AttendanceShiftRecord $record) => view('filament.components.attendance-detail', [
                'record' => $record->load(['fines', 'template', 'user']),
                'punches' => $this->punchesFor($record),
            ]));
    }

    /**
     * Every raw punch inside the shift's window, so somebody can see what the
     * engine saw — including the ones it collapsed away, which is usually the
     * answer to "why does it say they only punched once".
     */
    private function punchesFor(AttendanceShiftRecord $record): \Illuminate\Support\Collection
    {
        return \App\Models\AttendanceLog::query()
            ->where('biometric_id', $record->user?->biometric_id)
            ->whereBetween('punch_time', [$record->window_start_at, $record->window_end_at])
            ->orderBy('punch_time')
            ->get();
    }

    private function reevaluateAction(): Action
    {
        return Action::make('reevaluate')
            ->label('Re-evaluate this date')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            // Super admin only: it voids fines and rewrites judgements.
            ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false)
            ->modalDescription('Re-judges every shift on this date against the current punches and schedule. Existing records are superseded, not edited, and their fines are voided with your reason.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why?')
                    ->required()
                    ->helperText('Recorded on every record this changes and on every fine it voids.'),
            ])
            ->action(function (array $data) {
                $date = CarbonImmutable::parse($this->shiftDate(), VenueTime::TIMEZONE);

                $counts = app(ShiftReevaluationService::class)->reevaluate(
                    $date, $date, $data['reason'], null, auth()->user(),
                );

                $body = sprintf('%d examined, %d changed, %d unchanged.',
                    $counts['examined'], $counts['changed'], $counts['unchanged']);

                if ($counts['skipped_paid'] !== []) {
                    $body .= ' '.count($counts['skipped_paid']).' skipped — already in a payroll run.';
                }

                Notification::make()->success()->title('Re-evaluated')->body($body)->persistent()->send();

                $this->resetTable();
            });
    }

    public function shiftDate(): string
    {
        return $this->date ?: CarbonImmutable::now(VenueTime::TIMEZONE)->subDay()->toDateString();
    }
}
