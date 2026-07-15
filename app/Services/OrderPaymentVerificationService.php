<?php

namespace App\Services;

use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The cashier's primary, highest-volume surface: verifying transfer
 * payments one-by-one against the bank/money app. Rows resolve
 * independently of any settlement — she works the queue all day, and a
 * settlement's transfer channel just consumes whatever's already
 * resolved (CashierSettlementService::transferChannelComplete()).
 */
class OrderPaymentVerificationService
{
    public function verify(OrderPayment $payment, int $verifierId): OrderPayment
    {
        return DB::transaction(function () use ($payment, $verifierId) {
            $payment = OrderPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();
            $this->assertPendingTransfer($payment);

            $payment->update([
                'verified' => true,
                'verified_by' => $verifierId,
                'verified_at' => now(),
            ]);

            $this->maybeFinalizeOwningSettlement($payment, $verifierId);

            return $payment->fresh();
        });
    }

    public function flag(OrderPayment $payment, string $reason, string $flagReasonCode, int $flaggedById): OrderPayment
    {
        if (! in_array($flagReasonCode, ['not_found', 'amount_mismatch', 'duplicate'], true)) {
            throw new \Exception('Invalid flag reason.');
        }

        return DB::transaction(function () use ($payment, $reason, $flagReasonCode, $flaggedById) {
            $payment = OrderPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();
            $this->assertPendingTransfer($payment);

            $payment->update([
                'flagged' => true,
                'flag_reason' => $flagReasonCode,
                'flagged_by' => $flaggedById,
                'flagged_at' => now(),
            ]);

            activity('order_payment')
                ->performedOn($payment)
                ->causedBy(User::find($flaggedById))
                ->withProperties(['reason_code' => $flagReasonCode, 'note' => $reason])
                ->log('Transfer payment flagged');

            $this->notifySupervisors($payment);

            return $payment->fresh();
        });
    }

    private function assertPendingTransfer(OrderPayment $payment): void
    {
        if ($payment->method !== 'transfer') {
            throw new \Exception('Only a transfer payment can be verified or flagged.');
        }

        if ($payment->isResolved()) {
            throw new \Exception('This transfer payment has already been resolved.');
        }
    }

    private function maybeFinalizeOwningSettlement(OrderPayment $payment, int $actingUserId): void
    {
        if (! $payment->shift_id) {
            return;
        }

        $shift = $payment->shift;

        if ($shift && $shift->status === 'awaiting_cashier') {
            (new CashierSettlementService())->finalizeIfComplete($shift, $actingUserId);
        }
    }

    private function notifySupervisors(OrderPayment $payment): void
    {
        $supervisors = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'admin', 'super_admin']))->get();

        foreach ($supervisors as $supervisor) {
            \Filament\Notifications\Notification::make()
                ->title('Transfer payment flagged')
                ->body('₦' . number_format((float) $payment->amount, 2) . ' — ' . $payment->flag_reason)
                ->warning()
                ->sendToDatabase($supervisor);
        }
    }
}
