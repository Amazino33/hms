<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\PermissionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class FolioReceiptController extends Controller
{
    /**
     * Generated from the frozen checkout_snapshot only (see
     * BookingService::checkOut()) — never a live folio query, matching
     * HandoverPdfController's same "frozen seal-time snapshot" pattern —
     * so a later transfer verification/rejection on this folio can never
     * alter a receipt already handed to a guest who has left.
     */
    public function download(Booking $booking)
    {
        abort_unless(PermissionService::canAccessPage(\App\Filament\Pages\ReservationsTimeline::class), 403);
        abort_unless($booking->status === 'checked_out' && $booking->checkout_snapshot, 404);

        $pdf = Pdf::loadView('pdf.folio-receipt', ['booking' => $booking, 'snapshot' => $booking->checkout_snapshot]);
        $pdf->setPaper('a4');

        $guestSlug = Str::slug($booking->checkout_snapshot['guest_name'] ?? 'guest');
        $date = $booking->checked_out_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        return $pdf->download("folio-receipt-{$date}-{$guestSlug}.pdf");
    }
}
