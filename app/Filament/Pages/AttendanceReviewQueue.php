<?php

namespace App\Filament\Pages;

use App\Models\Attendance\AttendanceReviewItem;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Services\Attendance\ShiftEvaluator;
use App\Support\VenueTime;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Everything the engine could not decide on its own.
 *
 * None of it carries a charge. These are the cases where fining would be
 * punishing somebody for a problem that is not theirs — a punch with no
 * scheduled shift behind it, a day the terminal was probably offline, a
 * scheduled person nobody has linked to a badge.
 *
 * Marking an item reviewed records who and when and does nothing else: it is
 * an acknowledgement, not a correction. Fixing the underlying problem means
 * changing a rota or a link, which has its own screen.
 */
class AttendanceReviewQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Review Queue';

    protected static ?string $title = 'Attendance Review Queue';

    protected static ?string $slug = 'attendance-review';

    protected string $view = 'filament.pages.attendance-review-queue';

    /**
     * Shift records carrying a flag that wants a human eye. Shown alongside
     * the review items so there is one place to look rather than two.
     */
    public const FLAGGED = [
        ShiftEvaluator::FLAG_VERY_LATE,
        ShiftEvaluator::FLAG_SINGLE_PUNCH_LATE_HALF,
        ShiftEvaluator::FLAG_DEVICE_SILENT,
        ShiftEvaluator::FLAG_SUSPECTED_OUTAGE,
        ShiftEvaluator::FLAG_UNLINKED,
        ShiftEvaluator::FLAG_DAY_PAY_NOT_CONFIGURED,
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:AttendanceShiftTemplate') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AttendanceReviewItem::open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return AttendanceReviewItem::open()->exists() ? 'warning' : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AttendanceReviewItem::query()->with(['user', 'log']))
            ->columns([
                TextColumn::make('shift_date')->label('Date')->date()->sortable()->placeholder('—'),
                TextColumn::make('user.name')->label('Staff')->placeholder('—')->searchable(),
                TextColumn::make('reason')
                    ->label('What happened')
                    ->badge()
                    ->getStateUsing(fn (AttendanceReviewItem $r) => $r->reasonLabel())
                    ->color(fn (AttendanceReviewItem $r) => $r->reason === AttendanceReviewItem::SUSPECTED_OUTAGE ? 'danger' : 'warning'),
                TextColumn::make('detail')->label('Detail')->wrap(),
                TextColumn::make('resolved_at')
                    ->label('Reviewed')
                    ->dateTime()
                    ->placeholder('Open')
                    ->description(fn (AttendanceReviewItem $r) => $r->resolver?->name),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                \Filament\Tables\Filters\TernaryFilter::make('open')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Still open')
                    ->falseLabel('Already reviewed')
                    ->queries(
                        true: fn ($q) => $q->whereNull('resolved_at'),
                        false: fn ($q) => $q->whereNotNull('resolved_at'),
                        blank: fn ($q) => $q,
                    )
                    ->default(true),
            ])
            ->recordActions([
                Action::make('markReviewed')
                    ->label('Mark reviewed')
                    ->icon('heroicon-o-check')
                    ->visible(fn (AttendanceReviewItem $record) => $record->resolved_at === null)
                    ->modalDescription('Records that you have looked at this. It changes no record and voids no charge — if something needs correcting, fix the rota or the device link.')
                    ->requiresConfirmation()
                    ->action(function (AttendanceReviewItem $record) {
                        $record->markReviewed(auth()->user());

                        Notification::make()->success()->title('Marked reviewed')->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing needs reviewing')
            ->emptyStateDescription('No unscheduled punches and no suspected outages.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, AttendanceShiftRecord>
     */
    public function flaggedRecords(): \Illuminate\Support\Collection
    {
        return AttendanceShiftRecord::current()
            ->with(['user', 'template'])
            ->whereNotNull('review_flags')
            ->where('review_flags', '!=', '[]')
            ->orderByDesc('shift_date')
            ->limit(100)
            ->get()
            ->filter(fn (AttendanceShiftRecord $r) => array_intersect($r->review_flags ?? [], self::FLAGGED) !== []);
    }

    public function venueTimezone(): string
    {
        return VenueTime::TIMEZONE;
    }
}
