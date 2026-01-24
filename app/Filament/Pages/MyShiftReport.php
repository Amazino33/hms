<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\OrderPayment;
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
        $today = Carbon::today();
        $userId = Auth::id();

        // 1. My Payments (Money I touched)
        $myPayments = OrderPayment::where('user_id', $userId)
            ->whereDate('paid_at', $today)
            ->get();

        return [
            'date' => $today->format('l, d M Y'),
            'cash_hand' => $myPayments->where('method', 'cash')->sum('amount'),
            'pos_total' => $myPayments->whereIn('method', ['pos', 'transfer'])->sum('amount'),
            'total_collected' => $myPayments->sum('amount'),
            'transaction_count' => $myPayments->count(),
            'transactions' => $myPayments->sortByDesc('paid_at'),
        ];
    }
}