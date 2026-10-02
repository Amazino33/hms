<?php

namespace App\Services\Guest;

use App\Models\Booking;
use App\Models\GuestPaymentClaim;
use App\Models\GuestTableSession;
use App\Models\GuestTrustedDevice;
use App\Models\TransferAccount;
use App\Models\User;
use App\Services\FolioService;
use Illuminate\Support\Facades\DB;

/**
 * "I've paid" from a guest's phone (Phase 4). A claim is information for
 * the waiter's Mark Paid — it never pays anything (D12). Append-only (D23):
 * this service creates claims and lets a guest withdraw their own;
 * GuestTablePaymentService sets matched/unmatched at payment.
 *
 * Rooms (Phase 5): a claim belongs to the stay, only a trusted phone (D25)
 * may make one, and reception settles it — "Open folio payment" records
 * the transfer on the folio through FolioService::recordPayment() and
 * marks the claim matched; "Not received" marks it unmatched.
 */
class GuestClaimService
{
    public const MAX_OPEN_PER_DEVICE = 5;

    /**
     * @throws GuestRequestException
     */
    public function create(GuestTableSession $session, string $deviceId, mixed $payerName, mixed $amount, mixed $transferAccountId = null): GuestPaymentClaim
    {
        [$payerName, $amount, $accountId] = $this->validated($payerName, $amount, $transferAccountId);

        return DB::transaction(function () use ($session, $deviceId, $payerName, $amount, $accountId) {
            $session = GuestTableSession::lockForUpdate()->find($session->id);

            if (! $session?->isOpen()) {
                throw new GuestRequestException('closed', 'This table is closed — please ask your waiter.', 409);
            }

            $unpaid = GuestBillService::unpaidTotal($session);

            if ($unpaid <= 0) {
                throw new GuestRequestException('nothing_owed', 'There is nothing left to pay on this bill.', 409);
            }

            if ($amount > $unpaid) {
                throw new GuestRequestException('over_bill', 'That is more than the bill (₦'.number_format($unpaid).'). Check the amount and try again.');
            }

            $open = GuestPaymentClaim::where('guest_table_session_id', $session->id)->where('device_id', $deviceId)->open()->count();

            if ($open >= self::MAX_OPEN_PER_DEVICE) {
                throw new GuestRequestException('too_many_claims', 'You already have '.self::MAX_OPEN_PER_DEVICE.' payments waiting for your waiter to confirm.', 429);
            }

            $session->update(['last_activity_at' => now()]);

            return GuestPaymentClaim::create([
                'guest_table_session_id' => $session->id,
                'device_id' => $deviceId,
                'payer_name' => $payerName,
                'amount' => $amount,
                'transfer_account_id' => $accountId,
                'status' => GuestPaymentClaim::STATUS_OPEN,
            ]);
        });
    }

    /**
     * A room claim (Phase 5): against the stay's folio balance.
     *
     * @throws GuestRequestException
     */
    public function createForStay(Booking $stay, string $deviceId, mixed $payerName, mixed $amount, mixed $transferAccountId = null): GuestPaymentClaim
    {
        [$payerName, $amount, $accountId] = $this->validated($payerName, $amount, $transferAccountId);

        return DB::transaction(function () use ($stay, $deviceId, $payerName, $amount, $accountId) {
            $stay = Booking::lockForUpdate()->find($stay->id);

            if (! $stay?->isCheckedIn()) {
                throw new GuestRequestException('closed', 'Your stay has ended — please speak to reception.', 409);
            }

            if (! GuestTrustedDevice::isTrusted($stay->id, $deviceId)) {
                throw new GuestRequestException('not_trusted', 'Your bill appears after your first order is approved.', 403);
            }

            $owed = round((float) ($stay->folio?->balance() ?? 0), 2);

            if ($owed <= 0) {
                throw new GuestRequestException('nothing_owed', 'There is nothing left to pay on this bill.', 409);
            }

            if ($amount > $owed) {
                throw new GuestRequestException('over_bill', 'That is more than the bill (₦'.number_format($owed).'). Check the amount and try again.');
            }

            if (GuestPaymentClaim::where('stay_id', $stay->id)->where('device_id', $deviceId)->open()->count() >= self::MAX_OPEN_PER_DEVICE) {
                throw new GuestRequestException('too_many_claims', 'You already have '.self::MAX_OPEN_PER_DEVICE.' payments waiting for reception to confirm.', 429);
            }

            return GuestPaymentClaim::create([
                'stay_id' => $stay->id,
                'device_id' => $deviceId,
                'payer_name' => $payerName,
                'amount' => $amount,
                'transfer_account_id' => $accountId,
                'status' => GuestPaymentClaim::STATUS_OPEN,
            ]);
        });
    }

    /**
     * Reception's "Open folio payment" for a room claim: the existing folio
     * payment (FolioService::recordPayment, unchanged — a transfer, so it
     * awaits a manager's verification as always) and the claim → matched,
     * together.
     *
     * @throws \Exception
     */
    public function settleRoomClaim(GuestPaymentClaim $claim, User $receptionist, float $amount, string $payerReference): GuestPaymentClaim
    {
        return DB::transaction(function () use ($claim, $receptionist, $amount, $payerReference) {
            $claim = $this->lockOpenRoomClaim($claim, $receptionist);
            $stay = $claim->stay;
            $folio = $stay->folio ?? $stay->folio()->create();

            (new FolioService)->recordPayment($folio, $amount, 'transfer', trim($payerReference) ?: $claim->payer_name, $receptionist->id);

            $claim->update(['status' => GuestPaymentClaim::STATUS_MATCHED, 'status_set_at' => now()]);

            return $claim;
        });
    }

    /**
     * Reception checked: the transfer never arrived.
     *
     * @throws \Exception
     */
    public function markNotReceived(GuestPaymentClaim $claim, User $receptionist): GuestPaymentClaim
    {
        return DB::transaction(function () use ($claim, $receptionist) {
            $claim = $this->lockOpenRoomClaim($claim, $receptionist);
            $claim->update(['status' => GuestPaymentClaim::STATUS_UNMATCHED, 'status_set_at' => now()]);

            return $claim;
        });
    }

    /**
     * @throws \Exception
     */
    private function lockOpenRoomClaim(GuestPaymentClaim $claim, User $receptionist): GuestPaymentClaim
    {
        if (! $receptionist->hasRole(RoomRequestApprovalService::ROLES)) {
            throw new \Exception('Only reception or a manager can settle a room payment.');
        }

        $claim = GuestPaymentClaim::with('stay.folio')->lockForUpdate()->findOrFail($claim->id);

        if (! $claim->stay_id) {
            throw new \Exception('That is a table payment — the waiter settles it with Mark Paid.');
        }

        if ($claim->status !== GuestPaymentClaim::STATUS_OPEN) {
            throw new \Exception('This payment claim has already been settled.');
        }

        return $claim;
    }

    /**
     * @return array{0: string, 1: float, 2: ?int}
     *
     * @throws GuestRequestException
     */
    private function validated(mixed $payerName, mixed $amount, mixed $transferAccountId): array
    {
        $payerName = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $payerName)));

        if (mb_strlen($payerName) < 2 || mb_strlen($payerName) > 60) {
            throw new GuestRequestException('bad_name', 'Enter the name on the account you paid from (2 to 60 letters).');
        }

        $amount = is_numeric($amount) ? round((float) $amount, 2) : 0.0;

        if ($amount <= 0) {
            throw new GuestRequestException('bad_amount', 'Enter the amount you sent.');
        }

        $accountId = null;

        if ($transferAccountId !== null && $transferAccountId !== '') {
            $accountId = TransferAccount::active()->whereKey((int) $transferAccountId)->value('id');

            if (! $accountId) {
                throw new GuestRequestException('bad_account', 'Pick one of the accounts shown, or leave it blank.');
            }
        }

        return [$payerName, $amount, $accountId];
    }

    /**
     * @throws GuestRequestException
     */
    public function withdraw(GuestPaymentClaim $claim, string $deviceId): GuestPaymentClaim
    {
        return DB::transaction(function () use ($claim, $deviceId) {
            $claim = GuestPaymentClaim::lockForUpdate()->find($claim->id);

            if (! $claim || ! hash_equals($claim->device_id, $deviceId)) {
                throw new GuestRequestException('not_yours', 'You can only withdraw a payment sent from this phone.', 403);
            }

            if ($claim->status !== GuestPaymentClaim::STATUS_OPEN) {
                throw new GuestRequestException('not_open', 'Your waiter has already settled this payment.', 409);
            }

            $claim->update(['status' => GuestPaymentClaim::STATUS_WITHDRAWN, 'status_set_at' => now()]);

            return $claim;
        });
    }
}
