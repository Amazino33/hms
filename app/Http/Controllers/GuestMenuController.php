<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureGuestDevice;
use App\Models\Booking;
use App\Models\Company;
use App\Models\GuestPaymentClaim;
use App\Models\GuestRequest;
use App\Models\GuestTableSession;
use App\Models\GuestTrustedDevice;
use App\Models\Room;
use App\Models\Table;
use App\Models\TransferAccount;
use App\Services\BrandingLogo;
use App\Services\Guest\GuestBillService;
use App\Services\Guest\GuestClaimService;
use App\Services\Guest\GuestMenuService;
use App\Services\Guest\GuestOrderingSettings;
use App\Services\Guest\GuestRequestException;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\GuestRoundService;
use App\Services\Guest\GuestShifts;
use App\Services\Guest\GuestTableState;
use App\Services\Guest\GuestWaiterCallService;
use App\Services\Guest\GuestWhatsapp;
use App\Services\Guest\QrTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public guest QR menu (Phase 2): one HTML page with the whole menu
 * embedded, plus small JSON endpoints. Stateless (D9) — the phone is known
 * by its `selum_gd` device cookie, never a session.
 *
 * An unknown and a replaced token get the exact same friendly page with a
 * 404 status, so nobody can probe which codes ever existed. JSON errors are
 * {ok:false, code, message} with the right status — never a bare framework
 * error page; NoBareAbortTest enforces that.
 */
class GuestMenuController extends Controller
{
    public function __construct(
        private readonly GuestMenuService $menu,
        private readonly GuestRequestService $requests,
    ) {}

    public function place(Request $request, string $token): Response
    {
        $place = QrTokens::resolve($token);

        if (! $place) {
            return response()->view('guest.invalid-code', $this->brand(), 404);
        }

        $isTable = $place instanceof Table;
        // Rooms (Phase 5): ordering only during a checked-in stay.
        $stay = $isTable ? null : GuestRequestService::currentStay($place);

        return response()->view('guest.menu', $this->brand() + [
            'boot' => $this->bootData() + [
                'token' => $place->qr_token,
                'kind' => $isTable ? 'table' : 'room',
                'place' => $isTable ? $place->name : 'Room '.$place->number,
                'mode' => ($isTable || $stay) ? 'order' : 'browse',
                'notice' => ($isTable || $stay) ? null : GuestRequestService::NOT_STAYING_MESSAGE,
                'table' => $isTable
                    ? GuestTableState::present(GuestTableState::for($place, $this->deviceId($request)))
                    : $this->roomState($stay, $this->deviceId($request)),
                'accounts' => ($isTable || $stay) ? $this->accountList() : [],
                'whatsapp' => ! $isTable && GuestOrderingSettings::receptionWhatsapp() !== null,
            ],
        ]);
    }

    public function menuOnly(): Response
    {
        return response()->view('guest.menu', $this->brand() + [
            'boot' => $this->bootData() + [
                'token' => null,
                'place' => null,
                'kind' => null,
                'mode' => 'browse',
                'notice' => 'Scan the QR code on your table to order.',
                'table' => null,
                'accounts' => [],
            ],
        ]);
    }

    public function availability(string $token): JsonResponse
    {
        if (! QrTokens::resolve($token)) {
            return $this->invalidCode();
        }

        return response()->json(['ok' => true, 'unavailable' => $this->menu->unavailable()]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error('json_only', 'Please send your order from the menu page.', 415);
        }

        try {
            $guestRequest = $this->requests->submit(
                $token,
                $this->deviceId($request),
                (array) $request->json('lines', []),
                is_string($request->json('channel')) ? $request->json('channel') : null,
            );
        } catch (GuestRequestException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->context);
        }

        return response()->json([
            'ok' => true,
            'request' => $this->present($guestRequest),
            // Rooms (Phase 5): the phone opens this to send the order to reception.
            'whatsapp_url' => $guestRequest->isRoom() ? GuestWhatsapp::orderUrl($guestRequest) : null,
        ], 201);
    }

    public function index(Request $request, string $token): JsonResponse
    {
        $place = QrTokens::resolve($token);

        if (! $place) {
            return $this->invalidCode();
        }

        if ($place instanceof Room) {
            $stay = GuestRequestService::currentStay($place);
            $requests = $stay
                ? GuestRequest::where('stay_id', $stay->id)->where('device_id', $this->deviceId($request))->with('items')->latest('id')->get()
                : collect();

            return response()->json(['ok' => true, 'requests' => $requests->map(fn ($r) => $this->present($r))->values()]);
        }

        $session = GuestTableSession::open()->where('table_id', $place->id)->first();

        $requests = $session
            ? $session->requests()->where('device_id', $this->deviceId($request))->with('items')->latest('id')->get()
            : collect();

        return response()->json(['ok' => true, 'requests' => $requests->map(fn ($r) => $this->present($r))->values()]);
    }

    public function cancel(Request $request, string $token, string $ref): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error('json_only', 'Please cancel from the menu page.', 415);
        }

        $place = QrTokens::resolve($token);

        if (! $place) {
            return $this->invalidCode();
        }

        $guestRequest = GuestRequest::where($place instanceof Room ? 'room_id' : 'table_id', $place->id)->where('ref', $ref)->latest('id')->first();

        if (! $guestRequest) {
            return $this->error('not_found', 'We couldn\'t find that order.', 404);
        }

        try {
            $guestRequest = $this->requests->cancelByGuest($guestRequest, $this->deviceId($request));
        } catch (GuestRequestException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->context);
        }

        return response()->json(['ok' => true, 'request' => $this->present($guestRequest)]);
    }

    /** The live bill (Phase 4, D19) — only while a sitting is open at this table. */
    public function bill(Request $request, string $token): JsonResponse
    {
        $place = QrTokens::resolve($token);

        if (! $place) {
            return $this->invalidCode();
        }

        $device = $this->deviceId($request);

        // D25: a room's bill only reaches a phone reception has trusted.
        if ($place instanceof Room) {
            $stay = GuestRequestService::currentStay($place);
            $state = $this->roomState($stay, $device);

            return response()->json([
                'ok' => true,
                'table' => $state,
                'bill' => $state['trusted'] ? (new GuestBillService)->forStay($stay, $device) : null,
                'message' => match (true) {
                    ! $stay => GuestRequestService::NOT_STAYING_MESSAGE,
                    ! $state['trusted'] => 'Your bill appears after your first order is approved.',
                    default => null,
                },
            ]);
        }

        $state = GuestTableState::for($place, $device);

        return response()->json([
            'ok' => true,
            'table' => GuestTableState::present($state),
            'bill' => $state['session'] ? (new GuestBillService)->forSession($state['session'], $device) : null,
            'message' => $state['session'] ? null : 'No open bill',
        ]);
    }

    public function claim(Request $request, string $token): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error('json_only', 'Please send this from the bill page.', 415);
        }

        if (($place = QrTokens::resolve($token)) instanceof Room) {
            $stay = GuestRequestService::currentStay($place);

            if (! $stay) {
                return $this->error('not_staying', GuestRequestService::NOT_STAYING_MESSAGE, 409);
            }

            try {
                $claim = (new GuestClaimService)->createForStay($stay, $this->deviceId($request), $request->json('payer_name'), $request->json('amount'), $request->json('transfer_account_id'));
            } catch (GuestRequestException $e) {
                return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->context);
            }

            return response()->json(['ok' => true, 'claim' => ['id' => $claim->id, 'status' => $claim->status]], 201);
        }

        $session = $this->openSession($token);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        try {
            $claim = (new GuestClaimService)->create(
                $session,
                $this->deviceId($request),
                $request->json('payer_name'),
                $request->json('amount'),
                $request->json('transfer_account_id'),
            );
        } catch (GuestRequestException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->context);
        }

        return response()->json(['ok' => true, 'claim' => ['id' => $claim->id, 'status' => $claim->status]], 201);
    }

    public function withdrawClaim(Request $request, string $token, string $id): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error('json_only', 'Please send this from the bill page.', 415);
        }

        $place = QrTokens::resolve($token);

        if (! $place) {
            return $this->invalidCode();
        }

        $claim = ctype_digit($id) ? GuestPaymentClaim::with(['session', 'stay'])->find((int) $id) : null;
        $here = $place instanceof Room
            ? $claim?->stay && $claim->stay->room_id === $place->id
            : $claim?->session?->table_id === $place->id;

        // Another table's or room's claim is reported exactly like a missing one.
        if (! $claim || ! $here) {
            return $this->error('not_found', 'We couldn\'t find that payment.', 404);
        }

        try {
            $claim = (new GuestClaimService)->withdraw($claim, $this->deviceId($request));
        } catch (GuestRequestException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->context);
        }

        return response()->json(['ok' => true, 'claim' => ['id' => $claim->id, 'status' => $claim->status]]);
    }

    public function call(Request $request, string $token): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error('json_only', 'Please call from the menu page.', 415);
        }

        try {
            $call = (new GuestWaiterCallService)->create($token, $this->deviceId($request), $request->json('reason'), $request->json('note'));
        } catch (GuestRequestException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->context);
        }

        return response()->json(['ok' => true, 'call' => ['reason' => $call->label(), 'status' => $call->status]], 201);
    }

    /** "Another round" (D21): cart lines to review — nothing is sent. */
    public function round(Request $request, string $token): JsonResponse
    {
        if (($place = QrTokens::resolve($token)) instanceof Room) {
            $stay = GuestRequestService::currentStay($place);

            return $stay
                ? response()->json(['ok' => true] + (new GuestRoundService)->lastRound($stay, $this->deviceId($request)))
                : $this->error('not_staying', GuestRequestService::NOT_STAYING_MESSAGE, 409);
        }

        $session = $this->openSession($token);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        return response()->json(['ok' => true] + (new GuestRoundService)->lastRound($session, $this->deviceId($request)));
    }

    public function accounts(string $token): JsonResponse
    {
        if (! QrTokens::resolve($token)) {
            return $this->invalidCode();
        }

        return response()->json(['ok' => true, 'accounts' => $this->accountList()]);
    }

    /**
     * A room phone's standing (Phase 5): 'room' while a stay is checked in,
     * 'none' otherwise; trusted once reception has approved one of its
     * orders this stay (D25).
     *
     * @return array{state: string, trusted: bool}
     */
    private function roomState(?Booking $stay, string $deviceId): array
    {
        return [
            'state' => $stay ? 'room' : 'none',
            'trusted' => $stay !== null && GuestTrustedDevice::isTrusted($stay->id, $deviceId),
        ];
    }

    /** @return list<array{id: int, bank: string, name: string, number: string}> */
    private function accountList(): array
    {
        return TransferAccount::active()->get()->map(fn (TransferAccount $a) => [
            'id' => $a->id,
            'bank' => $a->bank_name,
            'name' => $a->account_name,
            'number' => $a->account_number,
        ])->values()->all();
    }

    private function openSession(string $token): GuestTableSession|JsonResponse
    {
        $place = QrTokens::resolve($token);

        if (! $place instanceof Table) {
            return $this->invalidCode();
        }

        return GuestTableSession::open()->where('table_id', $place->id)->first()
            ?? $this->error('no_bill', 'No open bill at this table yet.', 409);
    }

    /** @return array<string, mixed> */
    private function bootData(): array
    {
        return [
            'menu' => $this->menu->payload(),
            'unavailable' => $this->menu->unavailable(),
            'popular' => array_column($this->menu->popularTonight(), 'key'),
            'specials' => GuestOrderingSettings::liveSpecials(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(GuestRequest $request): array
    {
        $request->loadMissing(['items.order', 'items.porter', 'items.request', 'confirmedBy']);

        $lines = $request->items->map(function ($item) {
            [$label, $final] = GuestBillService::lineStatus($item);

            return [
                'name' => $item->name_snapshot,
                'qty' => $item->finalQuantity(),
                'qty_requested' => $item->quantity_requested,
                'price' => (int) round((float) $item->unit_price_snapshot),
                'chips' => $item->chips ?? [],
                'note' => $item->note,
                'status' => $item->status,
                'status_label' => $label,
                'final' => $final,
            ];
        })->values();

        $requestFinal = ! in_array($request->status, [GuestRequest::STATUS_PENDING, GuestRequest::STATUS_CONFIRMED], true);

        return [
            'ref' => $request->ref,
            'status' => $request->status,
            'status_label' => $request->status === GuestRequest::STATUS_CONFIRMED
                ? ($request->isRoom() ? 'Confirmed by reception' : 'Confirmed by '.GuestShifts::firstName($request->confirmedBy))
                : $request->statusLabel(),
            'pending' => $request->isPending(),
            // Polling stops once nothing on the request can change any more.
            'live' => ! $requestFinal && $lines->contains(fn ($line) => ! $line['final']),
            'total' => (int) round((float) $request->total_snapshot),
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'lines' => $lines,
        ];
    }

    private function deviceId(Request $request): string
    {
        return (string) $request->attributes->get(EnsureGuestDevice::ATTRIBUTE);
    }

    private function invalidCode(): JsonResponse
    {
        return $this->error('invalid_code', 'This code is no longer valid — please ask a staff member.', 404);
    }

    private function error(string $code, string $message, int $status, array $context = []): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $code, 'message' => $message] + $context, $status);
    }

    /**
     * Display-only page data (Phase 7A, D30): the venue name from Company
     * Settings — never typed into a template — and the public logo copies.
     *
     * @return array{venue: string, logo: ?string, splashLogo: ?string}
     */
    private function brand(): array
    {
        return [
            'venue' => Company::displayName(),
            'logo' => BrandingLogo::headerUrl(),
            'splashLogo' => BrandingLogo::splashUrl(),
        ];
    }
}
