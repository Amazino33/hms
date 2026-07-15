<?php

namespace App\Services\Ceo;

use App\Models\Booking;
use App\Models\FolioLine;
use App\Models\OrderPayment;
use App\Models\StaffDebt;

/**
 * Current-state ("as of now") figures only — never date-filtered. Backs
 * the dashboard's Tier 1 Total Exposure card and Tier 2 risk panel.
 */
class ExposureService
{
    public function outstandingStaffDebt(): float
    {
        return (float) StaffDebt::with('repayments')->get()->sum(fn (StaffDebt $d) => $d->remainingBalance());
    }

    /**
     * "Unverified" mirrors OrderPayment::isResolved()'s inverse for POS
     * transfers (not verified AND no ruling); hotel folio transfers have
     * no ruling workflow, just the verified flag.
     */
    public function unverifiedTransfers(): array
    {
        $orderPayments = OrderPayment::where('method', 'transfer')
            ->where('verified', false)
            ->whereNull('ruling')
            ->get(['amount', 'paid_at']);

        $folioLines = FolioLine::where('type', 'payment')
            ->where('payment_method', 'transfer')
            ->where('verified', false)
            ->get(['amount', 'created_at']);

        $count = $orderPayments->count() + $folioLines->count();
        $total = (float) $orderPayments->sum('amount') + (float) $folioLines->sum(fn (FolioLine $l) => abs((float) $l->amount));

        $oldest = collect([$orderPayments->min('paid_at'), $folioLines->min('created_at')])
            ->filter()
            ->sort()
            ->first();

        return ['count' => $count, 'total' => $total, 'oldest_at' => $oldest];
    }

    public function inHouseFolioBalances(): float
    {
        return (float) Booking::where('status', 'checked_in')
            ->with('folio.lines')
            ->get()
            ->sum(fn (Booking $b) => $b->folio ? $b->folio->balance() : 0.0);
    }

    public function totalExposure(): array
    {
        $debt = $this->outstandingStaffDebt();
        $transfers = $this->unverifiedTransfers();
        $folios = $this->inHouseFolioBalances();

        return [
            'staff_debt' => $debt,
            'unverified_transfers' => $transfers['total'],
            'in_house_folio_balances' => $folios,
            'total' => $debt + $transfers['total'] + $folios,
        ];
    }
}
