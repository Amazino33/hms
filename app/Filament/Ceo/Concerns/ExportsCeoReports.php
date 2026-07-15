<?php

namespace App\Filament\Ceo\Concerns;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared CSV/PDF export for every CEO report page — PDF follows the exact
 * pattern found during the Phase 1 audit (Pdf::loadView()->setPaper()),
 * rendered as a briefing document (header, summary block, then the table),
 * never a raw table dump.
 */
trait ExportsCeoReports
{
    protected function csvResponse(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    protected function pdfResponse(
        string $filename,
        string $reportTitle,
        string $filtersDescription,
        array $summary,
        array $tableHeaders,
        iterable $tableRows
    ): StreamedResponse {
        $pdf = Pdf::loadView('pdf.ceo.report', [
            'reportTitle' => $reportTitle,
            'filtersDescription' => $filtersDescription,
            'generatedAt' => now(),
            'generatedBy' => auth()->user()?->name,
            'summary' => $summary,
            'tableHeaders' => $tableHeaders,
            'tableRows' => $tableRows,
        ]);
        $pdf->setPaper('a4', 'landscape');

        return response()->streamDownload(fn () => print ($pdf->output()), $filename, ['Content-Type' => 'application/pdf']);
    }
}
