<?php

namespace App\Filament\Pages;

use App\Models\Attendance\AttendanceFine;
use App\Support\VenueTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * What a month would cost, per person, split shadow from live.
 *
 * Replaces the hand-built Excel lateness report. Shadow and live are kept
 * apart everywhere rather than summed: one is a projection and the other is
 * money, and a single total blurring the two is exactly how somebody ends up
 * being told they owe a figure that was never real.
 */
class MonthlyFinesReport extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Monthly Fines';

    protected static ?string $title = 'Monthly Fines Report';

    protected static ?string $slug = 'attendance-monthly-fines';

    protected string $view = 'filament.pages.monthly-fines-report';

    public ?string $month = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:AttendanceShiftTemplate') ?? false;
    }

    public function mount(): void
    {
        $this->month = CarbonImmutable::now(VenueTime::TIMEZONE)->format('Y-m');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('month')
                ->label('Month')
                ->options($this->monthOptions())
                ->live()
                ->native(false),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function monthOptions(): array
    {
        $options = [];
        $cursor = CarbonImmutable::now(VenueTime::TIMEZONE)->startOfMonth();

        for ($i = 0; $i < 18; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->format('F Y');
            $cursor = $cursor->subMonth();
        }

        return $options;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Download XLSX')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->export()),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function rows(): \Illuminate\Support\Collection
    {
        [$start, $end] = $this->bounds();

        $fines = AttendanceFine::query()
            ->with('user')
            ->active()
            ->whereDate('shift_date', '>=', $start->toDateString())
            ->whereDate('shift_date', '<=', $end->toDateString())
            ->get();

        return $fines->groupBy('user_id')->map(function ($userFines) {
            $sum = fn (callable $filter) => $userFines->filter($filter)->sum('amount');

            $byType = [];

            foreach (['late', 'late_relief', 'early_leave', 'no_clockout', 'absent'] as $type) {
                $byType[$type] = $userFines->where('type', $type)->sum('amount');
            }

            return [
                'staff' => $userFines->first()->user?->name ?? 'Unknown',
                'types' => $byType,
                'shadow_fines' => $sum(fn ($f) => $f->is_shadow && $f->kind === 'fine'),
                'live_fines' => $sum(fn ($f) => ! $f->is_shadow && $f->kind === 'fine'),
                'shadow_pay' => $sum(fn ($f) => $f->is_shadow && $f->kind === 'pay_deduction'),
                'live_pay' => $sum(fn ($f) => ! $f->is_shadow && $f->kind === 'pay_deduction'),
            ];
        })->sortBy('staff')->values();
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        $rows = $this->rows();

        return [
            'shadow_fines' => $rows->sum('shadow_fines'),
            'live_fines' => $rows->sum('live_fines'),
            'shadow_pay' => $rows->sum('shadow_pay'),
            'live_pay' => $rows->sum('live_pay'),
        ];
    }

    public function export(): StreamedResponse
    {
        $rows = $this->rows();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Fines '.$this->month);

        $headers = [
            'Staff', 'Late', 'Late relief', 'Left early', 'No clock-out', 'Absent',
            'Shadow fines', 'Live fines', 'Shadow pay withheld', 'Live pay withheld',
        ];

        $data = $rows->map(fn (array $r) => [
            $r['staff'],
            $r['types']['late'], $r['types']['late_relief'], $r['types']['early_leave'],
            $r['types']['no_clockout'], $r['types']['absent'],
            $r['shadow_fines'], $r['live_fines'], $r['shadow_pay'], $r['live_pay'],
        ])->all();

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        if ($data !== []) {
            $sheet->fromArray($data, null, 'A2');
        }

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = 'attendance-fines-'.$this->month.'.xlsx';

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(): array
    {
        $start = CarbonImmutable::parse(($this->month ?: CarbonImmutable::now(VenueTime::TIMEZONE)->format('Y-m')).'-01', VenueTime::TIMEZONE);

        return [$start->startOfMonth(), $start->endOfMonth()];
    }
}
