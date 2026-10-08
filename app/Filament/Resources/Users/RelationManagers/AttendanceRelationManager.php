<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Attendance\AttendanceFine;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * One person's attendance month: a calendar coloured by outcome, and the
 * charges underneath it.
 *
 * Coloured by shift_date, so an overnight shift shows on the day it began
 * rather than smeared across two. Splitting a 24h bar shift into two half-days
 * is precisely the calendar-day thinking this phase replaced.
 */
class AttendanceRelationManager extends RelationManager
{
    protected static string $relationship = 'attendanceShiftRecords';

    protected static ?string $title = 'Attendance';

    protected static \BackedEnum|string|null $icon = 'heroicon-o-clipboard-document-check';

    public ?string $month = null;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function mount(): void
    {
        parent::mount();

        $this->month ??= CarbonImmutable::now(VenueTime::TIMEZONE)->format('Y-m');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Attendance')
            ->description(fn () => $this->summary())
            ->modifyQueryUsing(fn ($query) => $query->whereNull('superseded_at'))
            ->columns([
                TextColumn::make('shift_date')->label('Date')->date()->sortable(),
                TextColumn::make('template.name')->label('Shift')->placeholder('—'),
                TextColumn::make('times')
                    ->label('In / Out')
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => ($r->clock_in_at?->timezone(VenueTime::TIMEZONE)->format('H:i') ?? '—')
                        .' / '.($r->clock_out_at?->timezone(VenueTime::TIMEZONE)->format('H:i') ?? '—')),
                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->badge()
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->outcomeLabel())
                    ->color(fn (AttendanceShiftRecord $r) => $r->outcomeColour()),
                TextColumn::make('fines_total')
                    ->label('Fines')
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->fines->whereNull('voided_at')->where('kind', 'fine')->sum('amount'))
                    ->money('ngn')
                    ->placeholder('—'),
                TextColumn::make('pay_total')
                    ->label('Pay withheld')
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->fines->whereNull('voided_at')->where('kind', 'pay_deduction')->sum('amount'))
                    ->money('ngn')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (AttendanceShiftRecord $r) => $r->fines->whereNull('voided_at')->where('is_shadow', false)->isNotEmpty() ? 'Live' : 'Shadow')
                    ->color(fn (string $state) => $state === 'Live' ? 'danger' : 'gray'),
            ])
            ->defaultSort('shift_date', 'desc')
            ->filters([
                \Filament\Tables\Filters\Filter::make('month')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('To'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('shift_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('shift_date', '<=', $d))),
            ])
            // Read-only: a judgement is changed by re-evaluating, never by
            // editing the row that justified a charge.
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No attendance recorded')
            ->emptyStateDescription('Either this person has no schedule, or no shifts of theirs have been judged yet.');
    }

    /**
     * The line under the heading: this month's totals, shadow and live kept
     * apart.
     */
    private function summary(): string
    {
        $start = CarbonImmutable::now(VenueTime::TIMEZONE)->startOfMonth();
        $end = $start->endOfMonth();

        $fines = AttendanceFine::query()
            ->active()
            ->where('user_id', $this->getOwnerRecord()->id)
            ->whereDate('shift_date', '>=', $start->toDateString())
            ->whereDate('shift_date', '<=', $end->toDateString())
            ->get();

        if ($fines->isEmpty()) {
            return $start->format('F Y').': nothing charged.';
        }

        $shadow = $fines->where('is_shadow', true)->sum('amount');
        $liveFines = $fines->where('is_shadow', false)->where('kind', 'fine')->sum('amount');
        $livePay = $fines->where('is_shadow', false)->where('kind', 'pay_deduction')->sum('amount');

        $parts = [];

        if ($liveFines > 0) {
            $parts[] = '₦'.number_format($liveFines).' in fines';
        }

        if ($livePay > 0) {
            $parts[] = '₦'.number_format($livePay).' pay withheld';
        }

        if ($shadow > 0) {
            $parts[] = '₦'.number_format($shadow).' shadow (not charged)';
        }

        return $start->format('F Y').': '.implode(', ', $parts).'.';
    }
}
