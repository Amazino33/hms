<?php

namespace App\Observers;

use App\Models\StaffDebt;
use App\Models\User;
use Filament\Notifications\Notification;

class StaffDebtObserver
{
    /**
     * Handle the StaffDebt "created" event.
     *
     * Alerts every supervisor-capable user whenever a debt opens (shortfall,
     * unpaid-order conversion, or manual), so it can't quietly sit
     * unnoticed. Severity is prioritized by amount — larger debts are
     * flagged more urgently.
     */
    public function created(StaffDebt $staffDebt): void
    {
        $staffDebt->loadMissing('user');

        $amount = (float) $staffDebt->amount;
        $severity = match (true) {
            $amount >= 20000 => 'danger',
            $amount >= 5000 => 'warning',
            default => 'info',
        };

        $reasonLabel = match ($staffDebt->reason) {
            'shift_shortfall' => 'shift shortfall',
            'unpaid_order_conversion' => 'unpaid order conversion',
            'count_session_shortfall' => 'handover count shortfall',
            default => 'manual entry',
        };

        $supervisors = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['manager', 'admin', 'super_admin']);
        })->get();

        foreach ($supervisors as $supervisor) {
            Notification::make()
                ->title('Staff debt opened: ' . ($staffDebt->user->name ?? 'Unknown'))
                ->body('₦' . number_format($amount, 2) . ' — ' . $reasonLabel)
                ->{$severity}()
                ->sendToDatabase($supervisor);
        }
    }
}
