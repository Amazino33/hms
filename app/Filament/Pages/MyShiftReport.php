<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\OrderPayment;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use BackedEnum;

class MyShiftReport extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'My Shift Summary';
    protected static ?int $navigationSort = 10;
    protected string $view = 'filament.pages.my-shift-report';

    // Only Waiters/Staff should see this (Hide from Admin if you want)
    public static function canAccess(): bool
    {
        return !Auth::user()->hasRole('admin'); 
    }

    protected function getViewData(): array
    {
        $userId = Auth::id();
        $currentShift = Shift::where('user_id', $userId)
            ->whereNull('ended_at')
            ->first();

        // Get shift history (completed shifts from today)
        $today = Carbon::today();
        $shiftHistory = Shift::where('user_id', $userId)
            ->whereNotNull('ended_at')
            ->whereDate('started_at', $today)
            ->with('payments')
            ->orderBy('ended_at', 'desc')
            ->get();

        if (!$currentShift && $shiftHistory->isEmpty()) {
            // No shifts today
            return [
                'shift_active' => false,
                'shift_start' => null,
                'shift_duration' => null,
                'cash_hand' => 0,
                'pos_total' => 0,
                'total_collected' => 0,
                'transaction_count' => 0,
                'transactions' => collect(),
                'total_debt' => 0,
                'partial_orders' => collect(),
                'shift_history' => collect(),
            ];
        }

        $currentShiftData = [];
        if ($currentShift) {
            // Current shift data
            $myPayments = OrderPayment::where('user_id', $userId)
                ->where('shift_id', $currentShift->id)
                ->get();

            $myPartialOrders = Order::where('user_id', $userId)
                ->where('status', 'partial')
                ->whereBetween('created_at', [$currentShift->started_at, now()])
                ->with('guest')
                ->get();

            $totalDebt = $myPartialOrders->sum(fn($order) => $order->total_amount - $order->amount_paid);

            $currentShiftData = [
                'shift_active' => true,
                'shift_start' => $currentShift->started_at->format('l, d M Y H:i'),
                'shift_duration' => $currentShift->started_at->diffForHumans(now(), true),
                'cash_hand' => $myPayments->where('method', 'cash')->sum('amount'),
                'pos_total' => $myPayments->whereIn('method', ['pos', 'transfer'])->sum('amount'),
                'total_collected' => $myPayments->sum('amount'),
                'transaction_count' => $myPayments->count(),
                'transactions' => $myPayments->sortByDesc('paid_at'),
                'total_debt' => $totalDebt,
                'partial_orders' => $myPartialOrders->sortByDesc('created_at'),
            ];
        } else {
            $currentShiftData = [
                'shift_active' => false,
                'shift_start' => null,
                'shift_duration' => null,
                'cash_hand' => 0,
                'pos_total' => 0,
                'total_collected' => 0,
                'transaction_count' => 0,
                'transactions' => collect(),
                'total_debt' => 0,
                'partial_orders' => collect(),
            ];
        }

        // Format shift history
        $formattedHistory = $shiftHistory->map(function ($shift) {
            return [
                'id' => $shift->id,
                'started_at' => $shift->started_at,
                'ended_at' => $shift->ended_at,
                'duration' => $shift->started_at->diffInMinutes($shift->ended_at),
                'total_payments' => $shift->payments->sum('amount'),
                'transaction_count' => $shift->payments->count(),
                'cash_payments' => $shift->payments->where('method', 'cash')->sum('amount'),
                'pos_payments' => $shift->payments->whereIn('method', ['pos', 'transfer'])->sum('amount'),
            ];
        });

        return array_merge($currentShiftData, [
            'shift_history' => $formattedHistory,
        ]);
    }
}