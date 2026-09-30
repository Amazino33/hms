<?php

namespace App\Filament\Concerns;

use App\Models\DailyAttendance;
use App\Support\VenueTime;
use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Download CSV" for the Daily Attendance table, shared by the admin and ceo
 * panels so the two can never export different things.
 *
 * Exports what is on screen, not what is on the page: the date filter and the
 * search box are honoured, pagination is not. Fifty rows visible out of a
 * thousand means a thousand rows in the file.
 */
trait ExportsDailyAttendance
{
    protected static function exportAction(): Action
    {
        return Action::make('export')
            ->label('Download CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (HasTable $livewire) => static::streamAttendanceCsv($livewire));
    }

    public static function streamAttendanceCsv(HasTable $livewire): StreamedResponse
    {
        // Filters, search and sort applied; the limit/offset of the current
        // page deliberately not. Eager-loaded because otherwise every row
        // fires two more queries for its user and its enrolment.
        $query = $livewire->getFilteredSortedTableQuery()->with(['user', 'enrollment']);

        $filename = 'daily-attendance-'.now()->timezone(VenueTime::TIMEZONE)->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // Excel assumes the system codepage for a bare CSV and mangles
            // any non-ASCII name; the BOM is what makes it read UTF-8.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, [
                'Date',
                'Staff Member',
                'Name on Machine',
                'Machine ID',
                'Status',
                'First Punch (In)',
                'Last Punch (Out)',
                'Expected Start',
                'Minutes Late',
            ]);

            // Chunked so a year of attendance cannot exhaust memory, and by
            // id because the view's own sort is not guaranteed unique.
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, static::attendanceCsvRow($row));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<int, string|int|null>
     */
    protected static function attendanceCsvRow(DailyAttendance $row): array
    {
        // ISO dates and 24-hour times throughout: a spreadsheet sorts those
        // correctly, where "Sep 27, 2026" and "07:59 AM" sort alphabetically
        // and put October before September.
        return [
            $row->date?->format('Y-m-d'),
            $row->user?->name,
            $row->enrollment?->name,
            $row->biometric_id,
            $row->status(),
            $row->firstPunchLocal()?->format('H:i'),
            $row->lastPunchLocal()?->format('H:i'),
            $row->expectedStartAt()?->format('H:i'),
            $row->minutesLate(),
        ];
    }
}
