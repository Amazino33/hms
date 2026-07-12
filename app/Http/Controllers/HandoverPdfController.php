<?php

namespace App\Http\Controllers;

use App\Models\CountSession;
use App\Services\PermissionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class HandoverPdfController extends Controller
{
    /**
     * Generated from the frozen seal-time snapshot only (CountSessionItem's
     * adjusted_expected_quantity/counted_quantity/variance/unit_selling_price/
     * variance_value, HandoverDiscrepancy's status at the moment of
     * generation) — never a live recomputation, so a later price change can
     * never alter a historic PDF.
     */
    public function download(CountSession $session)
    {
        $user = auth()->user();
        $isParticipant = in_array($user->id, [$session->outgoing_user_id, $session->incoming_user_id, $session->witness_user_id, $session->opened_by], true);
        $isManager = PermissionService::canAccessPage(\App\Filament\Pages\HandoverDiscrepancies::class);

        abort_unless($isParticipant || $isManager, 403);

        $session->load(['items.product', 'items.ingredient', 'items.discrepancy.resolvedBy', 'warehouse', 'outgoingUser', 'incomingUser', 'witnessUser']);

        $pdf = Pdf::loadView('pdf.handover-comparison', ['session' => $session]);
        $pdf->setPaper('a4');

        $outgoingSlug = Str::slug($session->outgoingUser?->name ?? 'session');
        $date = $session->reviewed_at?->format('Y-m-d') ?? $session->opened_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        return $pdf->download("handover-{$date}-{$outgoingSlug}.pdf");
    }
}
