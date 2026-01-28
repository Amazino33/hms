<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use Carbon\Carbon;

class StaffReportService
{
    /**
     * Get expected and collected cash for a destination (bar/kitchen/main) between dates.
     * Returns ['expected' => decimal, 'collected' => decimal]
     */
    public function expectedCashByDestination(string $destination, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from?->startOfDay() ?? Carbon::today()->startOfDay();
        $to = $to?->endOfDay() ?? Carbon::today()->endOfDay();

        // Expected: sum of outstanding amounts for orders in that destination
        $orders = Order::where('destination', $destination)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->get();

        $expected = $orders->sum(fn($o) => ($o->total_amount - ($o->amount_paid ?? 0)));

        // Collected: payments for orders in this destination
        $collected = OrderPayment::whereBetween('paid_at', [$from, $to])
            ->whereHas('order', fn($q) => $q->where('destination', $destination))
            ->sum('amount');

        return [
            'expected' => $expected,
            'collected' => $collected,
        ];
    }

    /**
     * Get staff history grouped by day for a user.
     * Returns array keyed by YYYY-MM-DD => [orders: Collection, total: decimal, payments: Collection, payments_total]
     * Includes both orders created by the user and orders processed by the user
     */
    public function staffDailyHistory(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from?->startOfDay() ?? Carbon::now()->subDays(30)->startOfDay();
        $to = $to?->endOfDay() ?? Carbon::now()->endOfDay();

        // Get orders created by the user OR orders processed by the user
        $orders = Order::with(['items', 'payments'])
            ->where(function($query) use ($userId) {
                $query->where('user_id', $userId) // Orders created by user
                      ->orWhere('processed_by_user_id', $userId); // Orders processed by user
            })
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn($o) => $o->created_at->format('Y-m-d'));

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
}
