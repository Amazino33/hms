<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The fast Mark Paid engine — settles a table's served orders in full, in
 * one action, by one OR several methods (Phase 0E "Split by method"). The
 * single-method buttons are just a one-line call into payWithMethods(), so
 * there is exactly one code path that writes fast-path payments.
 *
 * Never deletes or re-creates anything: it only appends OrderPayment rows
 * and sets the same two paid fields the original markPaidFast() always
 * set. That is the whole point of this existing alongside the full payment
 * screen (pos.blade.php processPayment()), which rebuilds orders instead.
 *
 * Not partial payment over time — the lines must add up to exactly what is
 * outstanding, so every order always ends this call fully paid.
 */
class FastMarkPaidService
{
    public const METHODS = ['cash', 'pos', 'transfer'];

    public const MAX_LINES = 3;

    public const PAYER_REFERENCE_MAX = 120;

    /**
     * @param  Collection<int, Order>  $orders  the table's served orders (re-read and locked here, never trusted as passed)
     * @param  array<int, array{method?: string, amount?: mixed, payer_reference?: ?string}>  $lines  1-3 lines, each method at most once, in the order the waiter entered them
     * @return float the total settled
     *
     * @throws \Exception with a message written for the person at the till
     */
    public function payWithMethods(Collection $orders, array $lines, User $actor): float
    {
        $lines = $this->normalizeLines($lines);

        $shiftId = $actor->currentShift()?->id;

        if (! $shiftId) {
            throw new \Exception('You must start a shift before processing payments.');
        }

        $orderIds = $orders->pluck('id')->all();

        if (empty($orderIds)) {
            throw new \Exception('There is nothing to pay at this table.');
        }

        return DB::transaction(function () use ($orderIds, $lines, $actor, $shiftId) {
            // Re-read under lock, deliberately WITHOUT a status filter: a
            // double-tap or a second device must see the rows the first
            // submission already paid and be refused, not silently find an
            // empty set and report "Paid ₦0".
            $orders = Order::whereIn('id', $orderIds)
                ->lockForUpdate()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($orders->count() !== count($orderIds)) {
                throw new \Exception('An order at this table changed while you were paying. Re-open the table and try again.');
            }

            if ($orders->contains(fn (Order $order) => $order->status !== 'served')) {
                throw new \Exception('This bill has already been paid, or an order is no longer waiting for payment. Re-open the table to see where it stands.');
            }

            // Kobo integers for every comparison and the allocation itself —
            // decimal naira compared as floats is how a ₦0.01 drift sneaks
            // past an "exactly equal" rule.
            $outstandingByOrder = $orders->mapWithKeys(fn (Order $order) => [
                $order->id => $this->toKobo(max(0, (float) $order->total_amount - (float) $order->amount_paid)),
            ]);

            $outstanding = $outstandingByOrder->sum();
            $entered = array_sum(array_column($lines, 'kobo'));

            if ($entered !== $outstanding) {
                $difference = $this->naira(abs($entered - $outstanding));

                throw new \Exception($entered < $outstanding
                    ? "The amounts are ₦{$difference} short of the ₦{$this->naira($outstanding)} bill. Adjust them so nothing remains."
                    : "The amounts are ₦{$difference} over the ₦{$this->naira($outstanding)} bill. Adjust them so nothing remains.");
            }

            // Deterministic allocation: oldest order first, each filled
            // from the lines in the order they were entered. An order can
            // straddle two lines (two rows); every order ends fully paid.
            $lineIndex = 0;
            $lineRemaining = $lines[0]['kobo'] ?? 0;

            foreach ($orders as $order) {
                $orderRemaining = $outstandingByOrder[$order->id];

                while ($orderRemaining > 0) {
                    while ($lineRemaining === 0) {
                        $lineIndex++;
                        $lineRemaining = $lines[$lineIndex]['kobo'];
                    }

                    $take = min($orderRemaining, $lineRemaining);
                    $line = $lines[$lineIndex];

                    OrderPayment::create([
                        'order_id' => $order->id,
                        'amount' => $take / 100,
                        'method' => $line['method'],
                        'user_id' => $actor->id,
                        'shift_id' => $shiftId,
                        'paid_at' => now(),
                        'payer_reference' => $line['payer_reference'],
                    ]);

                    $orderRemaining -= $take;
                    $lineRemaining -= $take;
                }

                $order->update([
                    'amount_paid' => $order->total_amount,
                    'status' => 'paid',
                ]);
            }

            return $outstanding / 100;
        });
    }

    /**
     * Shape + rule checks that need no database. Everything here is a
     * caller mistake or a tampered request, never a race.
     *
     * @return array<int, array{method: string, kobo: int, payer_reference: ?string}>
     */
    private function normalizeLines(array $lines): array
    {
        $lines = array_values($lines);

        if (count($lines) < 1 || count($lines) > self::MAX_LINES) {
            throw new \Exception('Use between 1 and '.self::MAX_LINES.' payment methods.');
        }

        $seen = [];
        $normalized = [];

        foreach ($lines as $line) {
            $method = $line['method'] ?? null;

            if (! in_array($method, self::METHODS, true)) {
                throw new \Exception('Choose Cash, POS or Transfer for every line.');
            }

            if (isset($seen[$method])) {
                throw new \Exception('Each payment method can only be used once. Combine the two '.ucfirst($method).' amounts into one line.');
            }

            $seen[$method] = true;

            $amount = $line['amount'] ?? null;

            if (! is_numeric($amount) || (float) $amount < 0) {
                throw new \Exception('Every amount must be a number of ₦0 or more.');
            }

            $kobo = $this->toKobo((float) $amount);

            // A zero line only makes sense as the single-method call on a
            // bill with nothing left owing (kept identical to the original
            // markPaidFast(), which marked such orders paid with no row).
            if ($kobo === 0 && count($lines) > 1) {
                throw new \Exception('Remove the empty line, or give it an amount.');
            }

            $reference = trim((string) ($line['payer_reference'] ?? ''));

            if ($reference !== '' && $method !== 'transfer') {
                throw new \Exception('A payer name or reference can only go on a Transfer line.');
            }

            if (mb_strlen($reference) > self::PAYER_REFERENCE_MAX) {
                throw new \Exception('The payer name or reference is too long — keep it to '.self::PAYER_REFERENCE_MAX.' characters.');
            }

            $normalized[] = [
                'method' => $method,
                'kobo' => $kobo,
                'payer_reference' => $reference === '' ? null : $reference,
            ];
        }

        return $normalized;
    }

    private function toKobo(float $naira): int
    {
        return (int) round($naira * 100);
    }

    private function naira(int $kobo): string
    {
        return number_format($kobo / 100, $kobo % 100 === 0 ? 0 : 2);
    }
}
