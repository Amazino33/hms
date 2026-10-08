<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceSimulator;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Back-tests the rules over a past month and writes a spreadsheet.
 *
 * Nothing is saved. The output exists to answer one question before anybody
 * is told the rules exist: what would this actually have cost people?
 */
class SimulateAttendance extends Command
{
    protected $signature = 'attendance:simulate
        {--from= : First shift date (Y-m-d, Lagos)}
        {--to= : Last shift date (Y-m-d, Lagos)}
        {--assume-current-schedules : Apply each person\'s current pattern to past dates}
        {--output= : Where to write the .xlsx}';

    protected $description = 'Back-test the attendance rules over past dates without writing anything';

    public function handle(AttendanceSimulator $simulator): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
            $this->error('Both --from and --to are required.');

            return self::FAILURE;
        }

        $result = $simulator->run(
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            (bool) $this->option('assume-current-schedules'),
        );

        foreach ($result['warnings'] as $warning) {
            $this->newLine();
            $this->warn($warning);
        }

        if ($result['rows']->isEmpty()) {
            $this->newLine();
            $this->warn('No shifts were produced for that range.');
            $this->line('Either nobody had a schedule covering it, or you want --assume-current-schedules.');

            return self::SUCCESS;
        }

        $this->summarise($result);

        $path = $this->option('output')
            ?: storage_path('app/attendance-simulation-'.$from.'-to-'.$to.'.xlsx');

        $this->write($result, $path, $from, $to);

        $this->newLine();
        $this->info('Written to '.$path);

        return self::SUCCESS;
    }

    private function summarise(array $result): void
    {
        $totals = $result['totals'];

        $this->newLine();
        $this->info('Simulation summary (nothing was saved)');
        $this->table(
            ['Measure', 'Value'],
            [
                ['Shifts evaluated', $totals['shifts']],
                ['Total fines', '₦'.number_format($totals['total_fines'])],
                ['Total pay deductions', '₦'.number_format($totals['total_pay_deductions'])],
                ['No clock-out (shift window)', $totals['shift_window_no_clockout']],
                ['No out time (old calendar-day view)', $totals['calendar_day_no_out_time']],
            ],
        );

        $artefact = $totals['calendar_day_no_out_time'] - $totals['shift_window_no_clockout'];

        if ($artefact > 0) {
            $this->line('  '.$artefact.' of the old view\'s "no out time" days were overnight shifts split at midnight.');
        }

        if ($totals['shift_window_no_clockout'] > 0) {
            $this->line('  '.$totals['shift_window_no_clockout'].' are real: a shift with only one punch in its whole window.');
        }
    }

    private function write(array $result, string $path, string $from, string $to): void
    {
        $book = new Spreadsheet;

        $perStaff = $book->getActiveSheet();
        $perStaff->setTitle('Per staff');
        $this->fill($perStaff, [
            'Staff', 'Scheduled', 'Present', 'Late', 'Late relief', 'Early leave',
            'No clock-out', 'Absent', 'Unlinked', 'Total fines', 'Total pay deductions',
        ], $result['perStaff']->map(fn (array $r) => array_values($r))->all());

        $shifts = $book->createSheet();
        $shifts->setTitle('Every shift');
        $this->fill($shifts, [
            'Staff', 'Date', 'Scheduled in', 'Scheduled out', 'Clock in', 'Clock out',
            'Late (min)', 'Early (min)', 'Outcome', 'Raw punches', 'Kept punches',
            'Fines', 'Pay deductions', 'Fine types', 'Flags',
        ], $result['rows']->map(fn (array $r) => [
            $r['staff'], $r['shift_date'], $r['scheduled_start'], $r['scheduled_end'],
            $r['clock_in'], $r['clock_out'], $r['late_minutes'], $r['early_leave_minutes'],
            $r['outcome'], $r['raw_punches'], $r['kept_punches'], $r['fines'],
            $r['pay_deductions'], $r['fine_types'], $r['flags'],
        ])->all());

        $totals = $book->createSheet();
        $totals->setTitle('Totals');
        $this->writeTotals($totals, $result, $from, $to);

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
    }

    private function fill($sheet, array $headers, array $rows): void
    {
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function writeTotals($sheet, array $result, string $from, string $to): void
    {
        $totals = $result['totals'];

        $rows = [
            ['Attendance simulation', ''],
            ['Range', $from.' to '.$to.' (Lagos)'],
            ['Generated', now()->timezone(VenueTime::TIMEZONE)->format('j M Y H:i')],
            ['Nothing was saved', 'This is a projection, not a record of charges'],
            ['', ''],
            ['Shifts evaluated', $totals['shifts']],
            ['Total fines (naira)', $totals['total_fines']],
            ['Total pay deductions (naira)', $totals['total_pay_deductions']],
            ['', ''],
            ['Count by fine type', ''],
        ];

        foreach ($totals['by_type'] as $type => $count) {
            $rows[] = [str_replace('_', ' ', ucfirst($type)), $count];
        }

        $rows[] = ['', ''];
        $rows[] = ['No clock-out comparison', ''];
        $rows[] = ['Shift-window no clock-out', $totals['shift_window_no_clockout']];
        $rows[] = ['Calendar-day "no out time" (old view)', $totals['calendar_day_no_out_time']];
        $rows[] = [
            'Difference (overnight shifts the old view split in two)',
            $totals['calendar_day_no_out_time'] - $totals['shift_window_no_clockout'],
        ];

        foreach ($result['warnings'] as $warning) {
            $rows[] = ['', ''];
            $rows[] = ['Warning', $warning];
        }

        $sheet->fromArray($rows, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->getStyle('B1:B'.count($rows))->getAlignment()->setWrapText(true);
    }
}
