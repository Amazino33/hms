<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;

class StaffReportService
{
    /**
     * Get expected and collected cash for a destination (bar/kitchen/main)
     * across a range of BUSINESS days, not calendar days — the widget above
     * this says "Collected Today", and for a bar "today" is the trading
     * night, which runs past midnight. On a UTC calendar day a sale at 1am
     * fell into tomorrow's figure and vanished from the number the
     * bartender was actually being measured on.
     *
     * Dates are business-day labels (Y-m-d), the same currency BusinessDay
     * deals in, so a caller can never accidentally hand this a midnight
     * that means something else.
     *
     * Returns ['expected' => decimal, 'collected' => decimal]
     */
    public function expectedCashByDestination(string $destination, ?string $fromBusinessDate = null, ?string $toBusinessDate = null): array
    {
        [$start, $end] = self::businessWindow($fromBusinessDate, $toBusinessDate);

        // Expected: sum of outstanding amounts for orders in that destination
        $orders = Order::where('destination', $destination)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->where('status', '!=', 'cancelled')
            ->get();

        $expected = $orders->sum(fn($o) => ($o->total_amount - ($o->amount_paid ?? 0)));

        // Collected: payments for orders in this destination
        $collected = OrderPayment::where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->whereHas('order', fn($q) => $q->where('destination', $destination))
            ->sum('amount');

        return [
            'expected' => $expected,
            'collected' => $collected,
        ];
    }

    /**
     * Get staff history grouped by BUSINESS day for a user.
     *
     * Grouping used to be created_at->format('Y-m-d') — a UTC calendar day
     * — so a waiter's 1am order filed under the following day and appeared
     * to belong to a shift they had already handed over. Keyed on
     * BusinessDay instead, a night's trading stays in one row, the same way
     * every owner/CEO report already counts it.
     *
     * Returns array keyed by business date (YYYY-MM-DD) => [orders: Collection, orders_total: decimal, payments: Collection, payments_total]
     * Includes both orders created by the user and orders processed by the user
     */
    public function staffDailyHistory(int $userId, ?string $fromBusinessDate = null, ?string $toBusinessDate = null): array
    {
        [$start, $end] = self::businessWindow(
            $fromBusinessDate ?? CarbonImmutable::parse(BusinessDay::today())->subDays(30)->toDateString(),
            $toBusinessDate,
        );

        // Get orders created by the user OR orders processed by the user
        $orders = Order::with(['items', 'payments'])
            ->where(function($query) use ($userId) {
                $query->where('user_id', $userId) // Orders created by user
                      ->orWhere('processed_by_user_id', $userId); // Orders processed by user
            })
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn($o) => BusinessDay::labelFor($o->created_at));

        $result = [];
        foreach ($orders as $date => $group) {
            $payments = $group->flatMap(fn($o) => $o->payments)->values();
            $result[$date] = [
                'orders' => $group,
                'orders_total' => $group->sum('total_amount'),
                'payments' => $payments,
                'payments_total' => $payments->sum('amount'),
            ];
        }

        return $result;
    }

    /**
     * The half-open [start, end) UTC instant range spanning a run of
     * business days. Half-open on purpose: BusinessDay::boundsFor() ends a
     * day at exactly the next one's 9am, so an inclusive BETWEEN would
     * count a 9am-sharp order in both days at once.
     *
     * @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable}
     */
    private static function businessWindow(?string $fromBusinessDate, ?string $toBusinessDate): array
    {
        [$start] = BusinessDay::boundsFor($fromBusinessDate ?? BusinessDay::today());
        [, $end] = BusinessDay::boundsFor($toBusinessDate ?? BusinessDay::today());

        return [$start, $end];
    }
}
