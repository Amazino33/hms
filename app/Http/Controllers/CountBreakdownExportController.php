<?php

namespace App\Http\Controllers;

use App\Models\CountSession;
use App\Services\CountBreakdownViewService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF and CSV of a sealed count's breakdown, and CSV of one item's trace
 * across counts. Everything is read from the frozen snapshot, so a file
 * downloaded later matches what was sealed.
 */
class CountBreakdownExportController extends Controller
{
    /**
     * Staff who took part get the staff version (no cost price); an
     * auditor gets the admin version unless they ask for the staff one.
     */
    public function pdf(Request $request, CountSession $session)
    {
        abort_unless(CountBreakdownViewService::canView($session, $request->user()), 403);

        $audit = CountBreakdownViewService::canAudit() && $request->query('variant') !== 'staff';
        $payload = (new CountBreakdownViewService)->payload($session, $audit, $request->user());

        $pdf = Pdf::loadView('pdf.count-breakdown', ['d' => $payload]);
        $pdf->setPaper('a4', 'landscape');

        $date = $session->reviewed_at?->venueTime()->format('Y-m-d') ?? 'count';

        return $pdf->download("count-{$session->id}-{$date}".($audit ? '-admin' : '').'.pdf');
    }

    public function csv(Request $request, CountSession $session): StreamedResponse
    {
        abort_unless($session->isReviewed() && CountBreakdownViewService::canAudit(), 403);

        $payload = (new CountBreakdownViewService)->payload($session, true, $request->user());

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Section', 'Item', 'Unit', 'B/F', 'Transferred', 'Returns', 'Other in', 'Available', 'Sold/Used', 'Sales NGN', 'Damages', 'Other out', 'Unrecorded', 'Expected', 'Counted', 'Variance', 'Unit price', 'Unit cost', 'Variance NGN (sell)', 'Variance NGN (cost)', 'Explanations']);

            foreach ($payload['sections'] ?? [] as $section) {
                foreach ($section['lines'] as $l) {
                    fputcsv($out, [
                        $section['label'], $l['name'], $l['unit'], $l['brought_forward'], $l['transferred'], $l['returns'], $l['other_in'],
                        $l['available'], $l['sold'], $l['sales_amount'], $l['damages'], $l['other_out'], $l['unrecorded'],
                        $l['expected'], $l['counted'], $l['variance'], $l['unit_price'], $l['unit_cost'], $l['value'], $l['value_cost'],
                        collect($l['notes'])->map(fn ($n) => "{$n['author']}: {$n['body']}")->implode(' | '),
                    ]);
                }
            }

            fclose($out);
        }, "count-{$session->id}-breakdown.csv", ['Content-Type' => 'text/csv']);
    }

    public function traceCsv(Request $request): StreamedResponse
    {
        abort_unless(CountBreakdownViewService::canAudit(), 403);

        $section = $request->query('section') === 'ingredient' ? 'ingredient' : 'product';
        $itemId = (int) $request->query('item');
        $rows = (new CountBreakdownViewService)->trace($section, $itemId);
        $name = Str::slug($rows->first()['line']->item_name ?? 'item');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Sealed', 'Count', 'Location', 'Outgoing', 'Incoming', 'B/F', 'Transferred', 'Returns', 'Sold/Used', 'Damages', 'Unrecorded', 'Expected', 'Counted', 'Variance', 'Variance NGN (sell)', 'Repeat shortage']);

            foreach ($rows as $row) {
                $l = $row['line'];
                fputcsv($out, [
                    $row['sealed_at'], $l->count_session_id, $row['session']?->warehouse?->name,
                    $row['session']?->outgoingUser?->name, $row['session']?->incomingUser?->name,
                    $l->brought_forward, $l->transferred_in, $l->returns_in, $l->sold_qty, $l->damages_writeoffs,
                    $l->unrecorded_change, $l->expected_remaining, $l->counted, $l->variance_qty, $l->variance_value_selling,
                    $row['repeat'] ? "Short {$row['repeat']['short']} of last {$row['repeat']['of']}" : '',
                ]);
            }

            fclose($out);
        }, "item-trace-{$name}.csv", ['Content-Type' => 'text/csv']);
    }
}
