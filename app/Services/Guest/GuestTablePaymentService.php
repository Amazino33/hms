<?php

namespace App\Services\Guest;

use App\Models\GuestPaymentClaim;
use App\Models\GuestTableSession;
use App\Models\User;
use App\Services\FastMarkPaidService;
use Illuminate\Support\Facades\DB;

/**
 * Mark Paid for a guest QR table (Phase 4). A wrapper only: the payment
 * itself is FastMarkPaidService, unchanged, run over the sitting's unpaid
 * bill orders (D19) instead of every order on the table.
 *
 * On success the sitting's open claims are settled in the same transaction
 * — 'matched' when a transfer line was used, otherwise 'unmatched' (D12).
 * If the payment fails, nothing about the claims changes.
 */
class GuestTablePaymentService
{
    public function __construct(private readonly FastMarkPaidService $payments = new FastMarkPaidService) {}

    /**
     * @param  array<int, array{method?: string, amount?: mixed, payer_reference?: ?string}>  $lines
     * @return float the total settled
     *
     * @throws \Exception with a message written for the waiter
     */
    public function pay(GuestTableSession $session, array $lines, User $waiter): float
    {
        return DB::transaction(function () use ($session, $lines, $waiter) {
            $session = GuestTableSession::lockForUpdate()->find($session->id);

            if (! $session?->isOpen()) {
                throw new \Exception('This guest table has already been closed.');
            }

            $orders = GuestBillService::unpaidOrders($session);

            if ($orders->isEmpty()) {
                throw new \Exception('There is nothing to pay on this guest bill.');
            }

            if ($orders->contains(fn ($order) => $order->status !== 'served')) {
                throw new \Exception('Some items are still cooking, or not yet confirmed served.');
            }

            $total = $this->payments->payWithMethods($orders, $lines, $waiter);

            $usedTransfer = collect($lines)->contains(fn ($line) => ($line['method'] ?? null) === 'transfer' && (float) ($line['amount'] ?? 0) > 0);

            GuestPaymentClaim::where('guest_table_session_id', $session->id)->open()->lockForUpdate()->get()
                ->each(fn (GuestPaymentClaim $claim) => $claim->update([
                    'status' => $usedTransfer ? GuestPaymentClaim::STATUS_MATCHED : GuestPaymentClaim::STATUS_UNMATCHED,
                    'status_set_at' => now(),
                ]));

            $session->update(['last_activity_at' => now()]);

            return $total;
        });
    }

    /**
     * What the Mark Paid panel pre-fills from the open claims: one transfer
     * line for their total (capped at the bill), the payer names joined as
     * its reference, and cash for the rest. The waiter can change all of it.
     *
     * @return array{outstanding: float, claims: list<array{name: string, amount: float, account: ?string}>, claimed: float, lines: list<array{method: string, amount: float, payer_reference: ?string}>}
     */
    public static function prefill(GuestTableSession $session): array
    {
        $outstanding = GuestBillService::unpaidTotal($session);
        $claims = GuestPaymentClaim::with('transferAccount')->where('guest_table_session_id', $session->id)->open()->oldest('id')->get();
        $claimed = round((float) $claims->sum('amount'), 2);

        $lines = [];

        if ($claims->isNotEmpty() && $outstanding > 0) {
            $transfer = min($claimed, $outstanding);
            $reference = mb_substr($claims->pluck('payer_name')->unique()->join(', '), 0, FastMarkPaidService::PAYER_REFERENCE_MAX);

            $lines[] = ['method' => 'transfer', 'amount' => $transfer, 'payer_reference' => $reference];

            if ($outstanding - $transfer > 0) {
                $lines[] = ['method' => 'cash', 'amount' => round($outstanding - $transfer, 2), 'payer_reference' => null];
            }
        }

        return [
            'outstanding' => $outstanding,
            'claims' => $claims->map(fn (GuestPaymentClaim $c) => [
                'name' => $c->payer_name,
                'amount' => (float) $c->amount,
                'account' => $c->transferAccount?->bank_name,
            ])->values()->all(),
            'claimed' => $claimed,
            'lines' => $lines,
        ];
    }
}
