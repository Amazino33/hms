<?php

namespace App\Services\Guest;

use App\Models\Booking;
use App\Models\GuestContact;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTrustedDevice;
use App\Models\User;
use App\Support\NigerianPhone;
use Illuminate\Support\Facades\DB;

/**
 * Reception's side of a room order (Phase 5) — the only place a room
 * request is approved, rejected, or edited before approval.
 *
 * Approval is one transaction: food becomes a room order at the guest's
 * price (billed to the folio with the request ref, stock at Mark Ready),
 * drinks wait at the bar, the phone becomes trusted for this stay (D25),
 * and the guest's WhatsApp number may be saved (D27). If anything is
 * refused, nothing changes.
 */
class RoomRequestApprovalService
{
    public const ROLES = ['receptionist', 'manager', 'admin', 'super_admin'];

    public const CHECKED_OUT_REASON = 'checked_out';

    /**
     * D29: reception may give fewer than asked, never more.
     *
     * @throws \Exception
     */
    public function reduce(GuestRequestItem $line, int $newQty, User $receptionist, string $reason, ?string $other = null): GuestRequestItem
    {
        $this->assertRole($receptionist);
        $reason = self::reasonText($reason, $other);

        return DB::transaction(function () use ($line, $newQty, $reason) {
            $line = GuestRequestItem::lockForUpdate()->findOrFail($line->id);
            $this->assertEditable($line);

            if ($newQty < 1 || $newQty >= $line->quantity_requested) {
                throw new \Exception("Reduce {$line->name_snapshot} to between 1 and ".($line->quantity_requested - 1).' — use ✕ to remove it completely.');
            }

            $line->update(['quantity_final' => $newQty, 'removed_reason' => "Reduced to {$newQty}: {$reason}"]);

            return $line;
        });
    }

    /**
     * @throws \Exception
     */
    public function remove(GuestRequestItem $line, User $receptionist, string $reason, ?string $other = null): GuestRequestItem
    {
        $this->assertRole($receptionist);
        $reason = self::reasonText($reason, $other);

        return DB::transaction(function () use ($line, $reason) {
            $line = GuestRequestItem::lockForUpdate()->findOrFail($line->id);
            $this->assertEditable($line);

            $line->update(['status' => 'removed', 'removed_reason' => $reason]);
            $request = $line->request;

            // Nothing left to approve: the request is rejected with that reason.
            if (! $request->items()->where('status', 'pending')->exists()) {
                $this->closeAsRejected($request, $reason);
            }

            return $line;
        });
    }

    /**
     * @throws \Exception with a message written for reception
     */
    public function approve(GuestRequest $request, User $receptionist, ?string $guestPhone = null, bool $optIn = false): GuestRequest
    {
        $this->assertRole($receptionist);

        $phone = null;

        if (filled($guestPhone)) {
            $phone = NigerianPhone::toInternational($guestPhone);

            if (! $phone) {
                throw new \Exception('That WhatsApp number isn\'t a Nigerian mobile. Enter it like 08012345678, or leave it empty.');
            }
        }

        return DB::transaction(function () use ($request, $receptionist, $phone, $optIn) {
            $request = GuestRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertPendingRoomRequest($request);

            $stay = Booking::lockForUpdate()->find($request->stay_id);

            if (! $stay || ! Booking::whereKey($stay->id)->currentlyCheckedIn()->exists()) {
                throw new \Exception("Room {$request->room?->number}'s guest is no longer checked in — reject {$request->ref} instead.");
            }

            $lines = $request->items()->where('status', 'pending')->get();

            if ($lines->isEmpty()) {
                throw new \Exception("Nothing is left on {$request->ref} to approve.");
            }

            $request->update([
                'status' => GuestRequest::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $receptionist->id,
            ]);

            $food = $lines->where('station', GuestStation::KITCHEN);

            if ($food->isNotEmpty()) {
                GuestRequestService::placeOrders($food, $request, $receptionist, null);
                GuestRequestItem::whereKey($food->pluck('id'))->update(['status' => 'ordered']);
            }

            GuestRequestItem::whereKey($lines->where('station', GuestStation::BAR)->pluck('id'))->update(['status' => 'at_bar']);

            // D25: this phone may now see the stay's bill.
            if (! GuestTrustedDevice::isTrusted($stay->id, $request->device_id)) {
                GuestTrustedDevice::create([
                    'stay_id' => $stay->id,
                    'device_id' => $request->device_id,
                    'first_approved_request_id' => $request->id,
                ]);
            }

            if ($phone) {
                $this->saveContact($stay, $phone, $optIn, $receptionist);
            }

            return $request->fresh('items');
        });
    }

    /**
     * @throws \Exception
     */
    public function reject(GuestRequest $request, User $receptionist, string $reason): GuestRequest
    {
        $this->assertRole($receptionist);
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('Say why the order is being rejected — the guest sees it.');
        }

        return DB::transaction(function () use ($request, $reason) {
            $request = GuestRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertPendingRoomRequest($request);
            $this->closeAsRejected($request, $reason);

            return $request->fresh('items');
        });
    }

    /**
     * Checkout (Phase 5): requests not yet approved, and drinks still at the
     * bar, are cancelled with reason "checked_out". Anything already ordered
     * is on the folio and goes through the normal checkout gate.
     */
    public function cancelForCheckout(Booking $stay): int
    {
        $cancelled = 0;

        GuestRequest::where('stay_id', $stay->id)
            ->whereIn('status', [GuestRequest::STATUS_PENDING, GuestRequest::STATUS_CONFIRMED])
            ->each(function (GuestRequest $request) use (&$cancelled) {
                DB::transaction(function () use ($request, &$cancelled) {
                    $request = GuestRequest::lockForUpdate()->find($request->id);

                    if ($request->isPending()) {
                        $this->closeAsRejected($request, self::CHECKED_OUT_REASON);
                        $cancelled++;

                        return;
                    }

                    $atBar = $request->items()->where('status', 'at_bar')->pluck('id');

                    if ($atBar->isNotEmpty()) {
                        GuestRequestItem::whereKey($atBar)->update(['status' => 'cancelled', 'removed_reason' => self::CHECKED_OUT_REASON]);
                        GuestRequestService::closeIfNothingLeft($request, self::CHECKED_OUT_REASON);
                        $cancelled++;
                    }
                });
            });

        return $cancelled;
    }

    private function closeAsRejected(GuestRequest $request, string $reason): void
    {
        $request->items()->where('status', 'pending')->update(['status' => 'cancelled', 'removed_reason' => $reason]);
        $request->update([
            'status' => GuestRequest::STATUS_CANCELLED_BY_STAFF,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);
    }

    private function saveContact(Booking $stay, string $phone, bool $optIn, User $by): void
    {
        $contact = GuestContact::where('stay_id', $stay->id)->where('phone', $phone)->first();

        if (! $contact) {
            GuestContact::create([
                'stay_id' => $stay->id,
                'phone' => $phone,
                'source' => 'whatsapp_order',
                'marketing_opt_in' => $optIn,
                'opted_in_at' => $optIn ? now() : null,
                'recorded_by_user_id' => $by->id,
            ]);

            return;
        }

        if ($optIn) {
            $contact->optIn($by);
        }
    }

    /**
     * @throws \Exception
     */
    private function assertPendingRoomRequest(GuestRequest $request): void
    {
        if (! $request->isRoom()) {
            throw new \Exception("{$request->ref} is a table order — its waiter accepts it.");
        }

        if (! $request->isPending()) {
            throw new \Exception("{$request->ref} has already been handled — it is {$request->statusLabel()}.");
        }
    }

    /**
     * @throws \Exception
     */
    private function assertEditable(GuestRequestItem $line): void
    {
        $this->assertPendingRoomRequest($line->request);

        if ($line->status !== 'pending') {
            throw new \Exception("{$line->name_snapshot} has already been removed.");
        }
    }

    /**
     * @throws \Exception
     */
    private function assertRole(User $user): void
    {
        if (! $user->hasRole(self::ROLES)) {
            throw new \Exception('Only reception or a manager can handle room orders.');
        }
    }

    /**
     * Same presets as the bar (D29).
     *
     * @throws \Exception
     */
    public static function reasonText(string $reason, ?string $other): string
    {
        if (! in_array($reason, GuestBarReleaseService::REASON_PRESETS, true)) {
            throw new \Exception('Pick a reason: out of stock, wrong item, or other.');
        }

        if ($reason !== 'Other') {
            return $reason;
        }

        $other = trim((string) $other);

        if ($other === '' || mb_strlen($other) > 100) {
            throw new \Exception('Say what the reason is, in up to 100 characters.');
        }

        return $other;
    }
}
