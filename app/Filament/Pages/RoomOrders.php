<?php

namespace App\Filament\Pages;

use App\Models\GuestPaymentClaim;
use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestWaiterCall;
use App\Models\User;
use App\Services\Guest\GuestBarReleaseService;
use App\Services\Guest\GuestClaimService;
use App\Services\Guest\GuestWaiterCallService;
use App\Services\Guest\RoomDeliveryService;
use App\Services\Guest\RoomRequestApprovalService;
use App\Services\PermissionService;
use App\Services\UserFeedback;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Reception's "Room Orders" (Phase 5): guest room orders from approval to
 * delivery, plus room payment claims and Call reception.
 *
 *   New orders      approve (food → kitchen, drinks → bar queue), reduce /
 *                   remove a line first (D29), or reject
 *   To deliver      send with a porter, or "Reception"
 *   Out for delivery Delivered, or Refused with a reason (D26)
 *
 * Every action re-checks this page's permission and goes through its own
 * service — the page only gathers input.
 */
class RoomOrders extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'Hotel';

    protected static ?string $navigationLabel = 'Room Orders';

    protected static ?string $title = 'Room Orders';

    protected string $view = 'filament.pages.room-orders';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    protected function allowed(): ?User
    {
        if (static::canAccess() && auth()->user()) {
            return auth()->user();
        }

        UserFeedback::blocked('Not allowed', 'Only reception and managers can handle room orders.');

        return null;
    }

    public function approve(int $requestId, ?string $phone = null, bool $optIn = false): void
    {
        $user = $this->allowed();
        $request = GuestRequest::find($requestId);

        if (! $user || ! $request) {
            return;
        }

        try {
            (new RoomRequestApprovalService)->approve($request, $user, $phone, $optIn);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't approve {$request->ref}", $e->getMessage());

            return;
        }

        UserFeedback::succeeded("{$request->ref} approved", 'Food has gone to the kitchen; drinks are with the bar.');
    }

    public function reject(int $requestId, string $reason): void
    {
        $user = $this->allowed();
        $request = GuestRequest::find($requestId);

        if (! $user || ! $request) {
            return;
        }

        try {
            (new RoomRequestApprovalService)->reject($request, $user, $reason);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't reject {$request->ref}", $e->getMessage());

            return;
        }

        UserFeedback::succeeded("{$request->ref} rejected");
    }

    public function reduceLine(int $lineId, int $qty, string $reason, ?string $other = null): void
    {
        $user = $this->allowed();
        $line = GuestRequestItem::find($lineId);

        if (! $user || ! $line) {
            return;
        }

        try {
            (new RoomRequestApprovalService)->reduce($line, $qty, $user, $reason, $other);
        } catch (\Exception $e) {
            UserFeedback::blocked('Not reduced', $e->getMessage());
        }
    }

    public function removeLine(int $lineId, string $reason, ?string $other = null): void
    {
        $user = $this->allowed();
        $line = GuestRequestItem::find($lineId);

        if (! $user || ! $line) {
            return;
        }

        try {
            (new RoomRequestApprovalService)->remove($line, $user, $reason, $other);
        } catch (\Exception $e) {
            UserFeedback::blocked('Not removed', $e->getMessage());
        }
    }

    /** @param  array<int, int>  $lineIds */
    public function dispatchLines(array $lineIds, ?int $porterId = null): void
    {
        $user = $this->allowed();

        if (! $user) {
            return;
        }

        try {
            (new RoomDeliveryService)->dispatch($lineIds, $porterId ? User::find($porterId) : null, $user);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't send it", $e->getMessage());

            return;
        }

        UserFeedback::succeeded('On its way');
    }

    /** @param  array<int, int>  $lineIds */
    public function markDelivered(array $lineIds): void
    {
        $user = $this->allowed();

        if (! $user) {
            return;
        }

        try {
            (new RoomDeliveryService)->delivered($lineIds, $user);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't mark delivered", $e->getMessage());

            return;
        }

        UserFeedback::succeeded('Delivered');
    }

    /** @param  array<int, int>  $lineIds */
    public function markRefused(array $lineIds, string $reason): void
    {
        $user = $this->allowed();

        if (! $user) {
            return;
        }

        try {
            (new RoomDeliveryService)->refused($lineIds, $reason, $user);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't record the refusal", $e->getMessage());

            return;
        }

        UserFeedback::succeeded('Refusal recorded', 'A manager decides it — nothing comes off the bill until then.');
    }

    public function settleClaim(int $claimId, float $amount, string $reference): void
    {
        $user = $this->allowed();
        $claim = GuestPaymentClaim::find($claimId);

        if (! $user || ! $claim) {
            return;
        }

        try {
            (new GuestClaimService)->settleRoomClaim($claim, $user, $amount, $reference);
        } catch (\Exception $e) {
            UserFeedback::blocked('Payment not recorded', $e->getMessage());

            return;
        }

        UserFeedback::succeeded('Payment recorded on the folio', 'A transfer still needs a manager to verify it, as always.');
    }

    public function claimNotReceived(int $claimId): void
    {
        $user = $this->allowed();
        $claim = GuestPaymentClaim::find($claimId);

        if (! $user || ! $claim) {
            return;
        }

        try {
            (new GuestClaimService)->markNotReceived($claim, $user);
        } catch (\Exception $e) {
            UserFeedback::blocked('Not changed', $e->getMessage());
        }
    }

    public function acknowledgeCall(int $callId): void
    {
        $user = $this->allowed();
        $call = GuestWaiterCall::find($callId);

        if ($user && $call && (new GuestWaiterCallService)->acknowledge($call, $user)) {
            UserFeedback::succeeded($call->placeName().' · on it');
        }
    }

    public function getViewData(): array
    {
        $roomRequests = fn () => GuestRequest::with(['room', 'stay.guest', 'items.porter'])->whereNotNull('room_id');

        $new = $roomRequests()->where('status', GuestRequest::STATUS_PENDING)->oldest('submitted_at')->get();

        $deliveryLines = GuestRequestItem::with(['request.room', 'request.stay.guest', 'porter'])
            ->whereIn('delivery_status', [GuestRequestItem::AWAITING_DISPATCH, GuestRequestItem::OUT_FOR_DELIVERY])
            ->orderBy('id')
            ->get();

        // One card per request (and porter, once out).
        $toDeliver = $deliveryLines->where('delivery_status', GuestRequestItem::AWAITING_DISPATCH)->groupBy('guest_request_id');
        $outForDelivery = $deliveryLines->where('delivery_status', GuestRequestItem::OUT_FOR_DELIVERY)
            ->groupBy(fn ($l) => $l->guest_request_id.'-'.($l->porter_user_id ?? 0));

        $claims = GuestPaymentClaim::with(['stay.room', 'transferAccount'])->whereNotNull('stay_id')->open()->oldest('id')->get();
        $calls = GuestWaiterCall::with('room')->whereNotNull('room_id')->live()->oldest('id')->get();

        return [
            'newOrders' => $new,
            'toDeliver' => $toDeliver,
            'outForDelivery' => $outForDelivery,
            'claims' => $claims,
            'calls' => $calls,
            'porters' => User::whereHas('roles', fn ($q) => $q->where('name', 'porter'))->whereNull('left_at')->orderBy('name')->get(['id', 'name']),
            'presets' => GuestBarReleaseService::REASON_PRESETS,
            // Anything new here chimes once (D16 sound bar on the page).
            'chimeIds' => collect()
                ->merge($new->pluck('id')->map(fn ($id) => "r{$id}"))
                ->merge($toDeliver->keys()->map(fn ($id) => "d{$id}"))
                ->merge($calls->pluck('id')->map(fn ($id) => "c{$id}"))
                ->merge($claims->pluck('id')->map(fn ($id) => "p{$id}"))
                ->values()->all(),
        ];
    }
}
