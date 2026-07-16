<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\OrderPayment;
use App\Models\Order;
use App\Models\Shift;
use App\Services\ShiftAccountingService;
use Illuminate\Support\Facades\Auth;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

class MyShiftReport extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'My Shift Summary';
    protected static ?int $navigationSort = 10;
    protected string $view = 'filament.pages.my-shift-report';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    /**
     * A supervisor converts one of THIS user's own outstanding orders into a
     * tracked staff debt (e.g. a guest walked out without paying). Only
     * usable from a waiter's own shift summary, and only by a supervisor.
     */
    public function convertToDebt(int $orderId): void
    {
        if (!auth()->user()->hasRole(['manager', 'admin', 'super_admin'])) {
            Notification::make()
                ->title('Not authorized')
                ->body('Only a supervisor can convert an unpaid order to a staff debt.')
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        $order = Order::find($orderId);

        if (!$order) {
            Notification::make()->title('Order not found')->danger()->persistent()->send();
            return;
        }

        (new ShiftAccountingService())->convertOrderToDebt($order, auth()->user());

        Notification::make()
            ->title('Order converted to staff debt')
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        $userId = Auth::id();
        $service = new ShiftAccountingService();

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

        // Open/partially-settled debts this user personally owes — repayment
        // history eager-loaded so a bartender sees their own itemized
        // payments (each recorded by a manager) without a second query per row.
        $myDebts = auth()->user()->debts()
            ->whereIn('status', ['open', 'partially_settled'])
            ->with('repayments.recordedBy')
            ->orderByDesc('created_at')
            ->get();
        $myDebtsTotal = $myDebts->sum(fn ($debt) => $debt->remainingBalance());

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
                'outstanding_orders' => collect(),
                'shift_history' => collect(),
                'my_debts' => $myDebts,
                'my_debts_total' => $myDebtsTotal,
                'can_convert_debt' => auth()->user()->hasRole(['manager', 'admin', 'super_admin']),
            ];
        }

        $currentShiftData = [];
        if ($currentShift) {
            $cashHand = $service->expectedCashRemittance($currentShift);
            $posTotal = $service->expectedPosTotal($currentShift);
            $outstandingOrders = $service->outstandingOrders($currentShift);

            $myPayments = OrderPayment::where('user_id', $userId)
                ->where('shift_id', $currentShift->id)
                ->get();

            $currentShiftData = [
                'shift_active' => true,
                'shift_start' => $currentShift->started_at->format('l, d M Y H:i'),
                'shift_duration' => $currentShift->started_at->diffForHumans(now(), true),
                'cash_hand' => $cashHand,
                'pos_total' => $posTotal,
                'total_collected' => $myPayments->sum('amount'),
                'transaction_count' => $myPayments->count(),
                'transactions' => $myPayments->sortByDesc('paid_at'),
                'total_debt' => $service->outstandingBalance($currentShift),
                'partial_orders' => $outstandingOrders,
                'outstanding_orders' => $outstandingOrders,
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
                'outstanding_orders' => collect(),
            ];
        }

        // Format shift history
        $formattedHistory = $shiftHistory->map(function ($shift) use ($service) {
            return [
                'id' => $shift->id,
                'started_at' => $shift->started_at,
                'ended_at' => $shift->ended_at,
                'duration' => $shift->started_at->diffInMinutes($shift->ended_at),
                'total_payments' => $shift->payments->sum('amount'),
                'transaction_count' => $shift->payments->count(),
                'cash_payments' => $service->expectedCashRemittance($shift),
                'pos_payments' => $service->expectedPosTotal($shift),
            ];
        });

        return array_merge($currentShiftData, [
            'shift_history' => $formattedHistory,
            'my_debts' => $myDebts,
            'my_debts_total' => $myDebtsTotal,
            'can_convert_debt' => auth()->user()->hasRole(['manager', 'admin', 'super_admin']),
        ]);
    }
}
