<?php

use App\Exceptions\PinLockedException;
use App\Models\GuestPaymentClaim;
use App\Models\GuestRequest;
use App\Models\GuestTableSession;
use App\Models\GuestWaiterCall;
use App\Models\Table;
use App\Models\User;
use App\Services\Guest\GuestBillService;
use App\Services\Guest\GuestRequestService;
use App\Services\Guest\GuestShifts;
use App\Services\Guest\GuestWaiterCallService;
use App\Services\Guest\SameGuestsQuestion;
use App\Services\Guest\TableCloseService;
use App\Services\Guest\TableMoveService;
use Illuminate\Support\Facades\Auth;
use App\Services\PinAuthService;
use App\Services\UserFeedback;
use Livewire\Component;

/**
 * "Guest orders" strip on the waiter kiosk and staff phone (Phase 3).
 * Pending guest requests, drinks the bar handed back for want of a waiter
 * (D3), and confirmed orders the assigned waiter may still cancel. Every
 * action is identified by the waiter's PIN at that moment — the shared
 * table grid itself stays PIN-free, so nobody is logged in by accepting.
 *
 * Phase 4 adds: "I've paid" claim cards (purple, information only), Call
 * waiter cards (blue, "On my way" is one tap with no PIN), the D19 "Same
 * guests?" question on a sitting's first acceptance, and Move / Close for
 * each open guest table (PIN).
 */
new class extends Component {
    public int $acceptableCount = 0;

    public int $oldestPendingSeconds = 0;

    /** Newest live waiter call — the page chimes once when it goes up. */
    public int $latestCallId = 0;

    protected function throttleKey(): string
    {
        $kioskDeviceId = session('kiosk_device_id');
        $trustedUserId = session('trusted_device_user_id');

        return $kioskDeviceId ? "kiosk:{$kioskDeviceId}" : ($trustedUserId ? "trusted:{$trustedUserId}" : 'unscoped:'.request()->ip());
    }

    /** On a personal phone, only its owner — the person we can grey cards for. */
    protected function deviceOwnerId(): ?int
    {
        return session('trusted_device_user_id') ? (int) session('trusted_device_user_id') : null;
    }

    protected function identify(string $pin): ?\App\Models\User
    {
        try {
            $user = (new PinAuthService)->attempt($pin, $this->throttleKey());
        } catch (PinLockedException $e) {
            UserFeedback::blocked('Too many wrong PINs', $e->getMessage());

            return null;
        }

        if (! $user) {
            UserFeedback::blocked('Incorrect PIN', 'Check your 4-digit PIN and try again.');

            return null;
        }

        if ($this->deviceOwnerId() && $user->id !== $this->deviceOwnerId()) {
            UserFeedback::blocked('Not your phone', 'This phone belongs to someone else — use your own PIN on your own phone.');

            return null;
        }

        return $user;
    }

    /**
     * @param  ?bool  $sameGuests  the D19 answer, when the card asked it
     */
    public function accept(int $requestId, string $pin, ?bool $sameGuests = null): void
    {
        $waiter = $this->identify($pin);
        $request = GuestRequest::find($requestId);

        if (! $waiter || ! $request) {
            return;
        }

        try {
            $request->isPending()
                ? (new GuestRequestService)->confirm($request, $waiter, $sameGuests)
                : (new GuestRequestService)->reacceptReturned($request, $waiter);
        } catch (SameGuestsQuestion $e) {
            UserFeedback::blocked('Same guests?', $e->getMessage().' Tap Accept again and answer.');

            return;
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't accept {$request->ref}", $e->getMessage());

            return;
        }

        UserFeedback::succeeded("{$request->ref} accepted", 'Food has gone to the kitchen; drinks are with the bar.');
    }

    public function cancel(int $requestId, string $reason, string $pin): void
    {
        $actor = $this->identify($pin);
        $request = GuestRequest::find($requestId);

        if (! $actor || ! $request) {
            return;
        }

        try {
            (new GuestRequestService)->cancelByStaff($request, $actor, $reason);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't cancel {$request->ref}", $e->getMessage());

            return;
        }

        UserFeedback::succeeded("{$request->ref} drinks cancelled");
    }

    /** D22: one tap, no PIN — records whoever is signed in on this screen, if anyone. */
    public function acknowledgeCall(int $callId): void
    {
        $call = GuestWaiterCall::find($callId);

        if (! $call) {
            return;
        }

        $by = Auth::guard('staff_pin')->user() ?? ($this->deviceOwnerId() ? User::find($this->deviceOwnerId()) : null);

        if ((new GuestWaiterCallService)->acknowledge($call, $by)) {
            UserFeedback::succeeded($call->table?->name.' · on your way');
        }
    }

    public function moveTable(int $sessionId, int $toTableId, string $pin): void
    {
        $actor = $this->identify($pin);
        $session = GuestTableSession::find($sessionId);
        $to = Table::find($toTableId);

        if (! $actor || ! $session || ! $to) {
            return;
        }

        $fromName = $session->getRelationValue('table')?->name;

        try {
            (new TableMoveService)->move($session, $to, $actor);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't move {$fromName}", $e->getMessage());

            return;
        }

        UserFeedback::succeeded("{$fromName} moved to {$to->name}", 'The bill and any waiting drinks moved with them.');
    }

    public function closeTable(int $sessionId, string $pin): void
    {
        $actor = $this->identify($pin);
        $session = GuestTableSession::with('table')->find($sessionId);

        if (! $actor || ! $session) {
            return;
        }

        try {
            (new TableCloseService)->close($session, $actor);
        } catch (\Exception $e) {
            UserFeedback::blocked("Couldn't close the table", $e->getMessage());

            return;
        }

        UserFeedback::succeeded($session->getRelationValue('table')?->name.' closed', 'The next guest order there starts a new bill.');
    }

    public function with(): array
    {
        $pending = GuestRequest::with(['table', 'items', 'session.assignedWaiter'])
            ->where('status', GuestRequest::STATUS_PENDING)
            ->whereHas('session', fn ($q) => $q->whereNull('closed_at'))
            ->oldest('submitted_at')
            ->get();

        $returned = GuestRequest::with(['table', 'items'])
            ->where('status', GuestRequest::STATUS_CONFIRMED)
            ->whereHas('items', fn ($q) => $q->where('status', 'needs_waiter'))
            ->oldest('submitted_at')
            ->get();

        $atBar = GuestRequest::with(['table', 'items', 'session.assignedWaiter'])
            ->where('status', GuestRequest::STATUS_CONFIRMED)
            ->whereHas('items', fn ($q) => $q->where('status', 'at_bar'))
            ->oldest('submitted_at')
            ->get();

        $owner = $this->deviceOwnerId();
        $cards = $pending->map(function (GuestRequest $r) use ($owner) {
            $assigned = $r->session?->assignedWaiter;
            $heldBy = ($assigned && GuestShifts::activeWaiterShift($assigned)) ? $assigned : null;

            $earlier = $r->session ? GuestRequestService::earlierUnpaid($r->session) : null;

            return [
                'request' => $r,
                'held_by' => $heldBy ? GuestShifts::firstName($heldBy) : null,
                // D19: asked before the PIN on a sitting's first acceptance.
                'unpaid_before' => $earlier ? (int) round($earlier['total']) : null,
                // On a personal phone we know who is looking; on a shared
                // kiosk the PIN decides, so the card stays tappable.
                'blocked' => $heldBy && $owner && $heldBy->id !== $owner,
            ];
        });

        // Phase 4: claims, calls, open guest tables.
        $claims = GuestPaymentClaim::with(['session.table', 'transferAccount'])
            ->open()
            ->whereHas('session', fn ($q) => $q->whereNull('closed_at'))
            ->oldest('id')
            ->get();

        $calls = GuestWaiterCall::with(['table', 'session.assignedWaiter'])->whereNotNull('table_id')->live()->oldest('id')->get()
            // On a personal phone: only this waiter's tables, or nobody's yet.
            ->filter(fn (GuestWaiterCall $c) => ! $owner || ! $c->session?->assigned_waiter_user_id || $c->session->assigned_waiter_user_id === $owner)
            ->values();
        $this->latestCallId = (int) $calls->max('id');

        $sessions = GuestTableSession::with(['table', 'assignedWaiter'])->open()->get()
            ->sortBy(fn ($s) => $s->getRelationValue('table')?->name)
            ->values();

        $this->acceptableCount = $cards->where('blocked', false)->count() + $returned->count();
        $oldest = $pending->first()?->submitted_at;
        $this->oldestPendingSeconds = $oldest ? (int) abs(now()->diffInSeconds($oldest)) : 0;

        return [
            'cards' => $cards,
            'returned' => $returned,
            'atBar' => $atBar,
            'claims' => $claims,
            'calls' => $calls,
            'sessions' => $sessions,
            'freeTables' => $sessions->isNotEmpty() ? TableMoveService::freeTables() : collect(),
            'ownerId' => $owner,
            'barShiftOpen' => GuestShifts::activeBartenderShifts()->isNotEmpty(),
        ];
    }
}; ?>

@php
    $ageClass = fn ($submittedAt) => match (true) {
        $submittedAt->lt(now()->subMinutes(10)) => 'bg-red-600',
        $submittedAt->lt(now()->subMinutes(5)) => 'bg-amber-500 text-black',
        default => 'bg-emerald-600',
    };
    $lineText = fn ($line) => $line->finalQuantity().'× '.$line->name_snapshot;
@endphp

<div wire:poll.5s class="mb-6"
    x-data="{
        sound: false,
        lastChime: 0,
        lastLoud: 0,
        pinFor: null, mode: 'accept', pin: '', reason: '',
        same: null, askSame: null, moveFor: null, moveTo: null, seenCall: 0,
        init() {
            this.sound = window.hmsChime.enabled();
            this.seenCall = $wire.latestCallId;
            setInterval(() => this.tick(), 1000);
        },
        enableSound() { this.sound = window.hmsChime.enable(); setTimeout(() => this.sound = window.hmsChime.enabled(), 300); },
        tick() {
            this.sound = window.hmsChime.enabled();
            const now = Date.now();
            if ($wire.acceptableCount > 0 && now - this.lastChime >= 20000) {
                this.lastChime = now;
                window.hmsChime.play(false);
            }
            if ($wire.acceptableCount > 0 && $wire.oldestPendingSeconds > 120 && now - this.lastLoud >= 120000) {
                this.lastLoud = now;
                window.hmsChime.play(true);
            }
            // One chime per new waiter call.
            if ($wire.latestCallId > this.seenCall) {
                window.hmsChime.play(false);
            }
            this.seenCall = Math.max(this.seenCall, $wire.latestCallId);
        },
        open(id, mode) { this.pinFor = id; this.mode = mode; this.pin = ''; this.reason = ''; },
        accept(id, unpaid, table) {
            this.same = null;
            if (unpaid) { this.askSame = { id, unpaid, table }; return; }
            this.open(id, 'accept');
        },
        answer(same) { const id = this.askSame.id; this.askSame = null; this.same = same; this.open(id, 'accept'); },
        startMove(sessionId) { this.moveFor = sessionId; this.moveTo = null; },
        pickMove(tableId) { const id = this.moveFor; this.moveFor = null; this.moveTo = tableId; this.open(id, 'move'); },
        press(d) {
            if (this.pin.length >= 4) return;
            this.pin += d;
            if (this.pin.length === 4) {
                const pin = this.pin, id = this.pinFor;
                this.pinFor = null;
                if (this.mode === 'cancel') $wire.cancel(id, this.reason, pin);
                else if (this.mode === 'move') $wire.moveTable(id, this.moveTo, pin);
                else if (this.mode === 'close') $wire.closeTable(id, pin);
                else $wire.accept(id, pin, this.same);
            }
        },
    }">
    @include('partials.guest-chime')

    <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
        <h2 class="text-white text-xl font-bold flex items-center gap-2">
            Guest orders
            @if ($cards->isNotEmpty() || $returned->isNotEmpty())
                <span class="text-sm bg-amber-500 text-black rounded-full px-2.5 py-0.5">{{ $cards->count() + $returned->count() }}</span>
            @endif
        </h2>
        <div class="flex items-center gap-2">
            @unless ($barShiftOpen)
                <span class="text-xs font-bold bg-red-600 text-white rounded-full px-3 py-1">Bar shift not started</span>
            @endunless
            <span x-show="!sound" x-cloak class="text-xs font-bold bg-gray-700 text-gray-200 rounded-full px-3 py-1">🔇 Sound off</span>
        </div>
    </div>

    <button type="button" x-show="!sound" x-cloak @click="enableSound()"
        class="w-full mb-3 h-12 rounded-xl bg-gray-800 border border-gray-600 text-gray-100 font-semibold">
        🔔 Tap to enable order sounds
    </button>

    {{-- Phase 4: Call waiter (blue) and "I've paid" claims (purple) --}}
    @if ($calls->isNotEmpty() || $claims->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 mb-3">
            @foreach ($calls as $call)
                @php $mine = $call->session?->assigned_waiter_user_id && $call->session->assigned_waiter_user_id === $ownerId; @endphp
                <div wire:key="call-{{ $call->id }}" class="rounded-2xl p-4 bg-sky-900/70 border-2 {{ $mine ? 'border-yellow-300' : 'border-sky-400' }} text-white">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-xl font-black">{{ $call->table?->name }} · {{ $call->label() }}</div>
                        <span class="text-xs font-bold bg-sky-600 rounded-full px-2 py-1">{{ $call->created_at->diffForHumans(now(), true) }}</span>
                    </div>
                    <div class="text-sm text-sky-200">
                        Guest is calling{{ $call->session?->assignedWaiter ? ' · '.\App\Services\Guest\GuestShifts::firstName($call->session->assignedWaiter).'\'s table' : ' · any waiter' }}
                    </div>
                    <button type="button" wire:click="acknowledgeCall({{ $call->id }})" class="mt-3 w-full min-h-[56px] rounded-xl bg-sky-500 font-bold text-lg">On my way</button>
                </div>
            @endforeach

            @foreach ($claims as $claim)
                <div wire:key="claim-{{ $claim->id }}" class="rounded-2xl p-4 bg-purple-900/70 border-2 border-purple-400 text-white">
                    <div class="text-lg font-bold">
                        {{ $claim->session?->table?->name }} · {{ $claim->payer_name }} says they paid ₦{{ number_format((float) $claim->amount) }} by transfer{{ $claim->transferAccount ? ' → '.$claim->transferAccount->bank_name : '' }}
                    </div>
                    <div class="text-sm text-purple-200 mt-1">Check it arrived, then settle the table with Mark Paid.</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($cards->isEmpty() && $returned->isEmpty() && $atBar->isEmpty() && $calls->isEmpty() && $claims->isEmpty())
        <p class="text-gray-500 text-sm">No guest orders waiting.</p>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3"
            @if ($acceptableCount > 0) style="animation: hmsFlash 1s ease-in-out infinite;" @endif>
            @foreach ($returned as $request)
                <div wire:key="ret-{{ $request->id }}" class="rounded-2xl p-4 bg-red-900/60 border-2 border-red-500 text-white">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-2xl font-black">{{ $request->table?->name }}</div>
                        <span class="text-xs font-bold bg-red-600 rounded-full px-2 py-1">Returned from bar</span>
                    </div>
                    <div class="text-sm text-red-200">{{ $request->ref }} · needs a waiter</div>
                    <ul class="mt-2 space-y-1">
                        @foreach ($request->items->where('status', 'needs_waiter') as $line)
                            <li class="font-semibold">{{ $lineText($line) }}
                                @if ($line->chips || $line->note)<span class="block font-extrabold text-yellow-300">{{ implode(' · ', $line->chips ?? []) }} @if($line->note)“{{ $line->note }}”@endif</span>@endif
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" @click="open({{ $request->id }}, 'accept')" class="mt-3 w-full min-h-[56px] rounded-xl bg-emerald-600 font-bold text-lg">Take this table</button>
                </div>
            @endforeach

            @foreach ($cards as $card)
                @php $request = $card['request']; @endphp
                <div wire:key="req-{{ $request->id }}" class="rounded-2xl p-4 border-2 text-white {{ $card['blocked'] ? 'bg-gray-800 border-gray-700 opacity-60' : 'bg-gray-800 border-amber-500' }}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-2xl font-black">{{ $request->table?->name }}</div>
                        <span class="text-xs font-bold rounded-full px-2 py-1 {{ $ageClass($request->submitted_at) }}">{{ $request->submitted_at->diffForHumans(now(), true) }}</span>
                    </div>
                    <div class="text-sm text-gray-400">{{ $request->ref }}@if ($card['held_by']) · {{ $card['held_by'] }}'s table @endif</div>
                    <ul class="mt-2 space-y-1">
                        @foreach ($request->items as $line)
                            <li class="font-semibold">{{ $lineText($line) }}
                                @if ($line->chips || $line->note)<span class="block font-extrabold text-yellow-300">{{ implode(' · ', $line->chips ?? []) }} @if($line->note)“{{ $line->note }}”@endif</span>@endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-2 font-bold text-amber-400">₦{{ number_format((float) $request->total_snapshot) }}</div>
                    @unless ($card['blocked'])
                        <button type="button" @click="accept({{ $request->id }}, {{ $card['unpaid_before'] ?? 'null' }}, @js($request->table?->name))" class="mt-3 w-full min-h-[56px] rounded-xl bg-emerald-600 font-bold text-lg">
                            Accept{{ $card['held_by'] ? ' ('.$card['held_by'].' only)' : '' }}
                        </button>
                    @endunless
                </div>
            @endforeach

            @foreach ($atBar as $request)
                <div wire:key="bar-{{ $request->id }}" class="rounded-2xl p-4 bg-gray-900 border border-gray-700 text-gray-300">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-lg font-bold text-white">{{ $request->table?->name }}</div>
                        <span class="text-xs font-bold bg-sky-700 text-white rounded-full px-2 py-1">At the bar</span>
                    </div>
                    <div class="text-sm text-gray-500">{{ $request->ref }} · {{ \App\Services\Guest\GuestShifts::firstName($request->session?->assignedWaiter) }}</div>
                    <ul class="mt-1 text-sm">
                        @foreach ($request->items->where('status', 'at_bar') as $line)
                            <li>{{ $lineText($line) }}</li>
                        @endforeach
                    </ul>
                    <button type="button" @click="open({{ $request->id }}, 'cancel')" class="mt-2 text-sm text-red-400 underline min-h-[44px]">Cancel these drinks</button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Phase 4: open guest tables — Move and Close (PIN) --}}
    @if ($sessions->isNotEmpty())
        <div class="mt-4">
            <div class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-2">Guest tables</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($sessions as $session)
                    <div wire:key="ses-{{ $session->id }}" class="flex items-center gap-2 rounded-xl bg-gray-800 border border-gray-700 pl-3 pr-1 py-1 text-white">
                        <span class="font-bold">{{ $session->getRelationValue('table')?->name }}</span>
                        <span class="text-xs text-gray-400">{{ $session->assignedWaiter ? \App\Services\Guest\GuestShifts::firstName($session->assignedWaiter) : 'no waiter yet' }}</span>
                        <button type="button" @click="startMove({{ $session->id }})" class="min-h-[44px] px-3 rounded-lg bg-gray-700 text-sm font-semibold">Move table</button>
                        <button type="button" @click="open({{ $session->id }}, 'close')" class="min-h-[44px] px-3 rounded-lg bg-gray-700 text-sm font-semibold">Close table</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- D19: "Same guests?" before the PIN on a sitting's first acceptance --}}
    <div x-show="askSame" x-cloak class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" @click.self="askSame = null">
        <div class="bg-white rounded-2xl p-6 w-full max-w-sm text-center">
            <h3 class="text-xl font-bold text-gray-900" x-text="askSame ? askSame.table + ' already has ₦' + Number(askSame.unpaid).toLocaleString() + ' unpaid. Same guests?' : ''"></h3>
            <p class="text-sm text-gray-500 mt-2">Yes adds it to these guests' bill. No keeps it separate, to be settled on its own.</p>
            <button type="button" @click="answer(true)" class="mt-4 w-full min-h-[64px] rounded-xl bg-emerald-600 text-white text-lg font-bold">Yes, same guests</button>
            <button type="button" @click="answer(false)" class="mt-3 w-full min-h-[64px] rounded-xl bg-gray-800 text-white text-lg font-bold">No, different</button>
        </div>
    </div>

    {{-- Move: free tables only, then the PIN --}}
    <div x-show="moveFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" @click.self="moveFor = null">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md">
            <h3 class="text-lg font-bold text-gray-900 text-center">Move to which table?</h3>
            @if ($freeTables->isEmpty())
                <p class="text-center text-gray-500 mt-3">No free table right now.</p>
            @else
                <div class="grid grid-cols-3 gap-2 mt-4 max-h-[60vh] overflow-y-auto">
                    @foreach ($freeTables as $free)
                        <button type="button" @click="pickMove({{ $free->id }})" class="min-h-[56px] rounded-xl bg-gray-100 font-bold text-gray-900">{{ $free->name }}</button>
                    @endforeach
                </div>
            @endif
            <button type="button" @click="moveFor = null" class="mt-4 w-full h-12 rounded-xl bg-gray-200 font-semibold text-gray-900">Cancel</button>
        </div>
    </div>

    {{-- PIN pad: accept, cancel (with a reason), move, close --}}
    <div x-show="pinFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" @click.self="pinFor = null">
        <div class="bg-white rounded-2xl p-6 w-full max-w-xs">
            <h3 class="text-lg font-bold text-gray-900 text-center" x-text="({ cancel: 'Cancel drinks', move: 'Move table', close: 'Close table' }[mode] || 'Accept') + ' — your PIN'"></h3>
            <template x-if="mode === 'cancel'">
                <input type="text" x-model="reason" maxlength="100" placeholder="Reason (required)" class="mt-3 w-full h-11 rounded-lg border border-gray-300 px-3 text-gray-900">
            </template>
            <div class="flex justify-center gap-3 my-4">
                <template x-for="i in 4" :key="i"><span class="w-4 h-4 rounded-full" :class="pin.length >= i ? 'bg-gray-900' : 'bg-gray-300'"></span></template>
            </div>
            <div class="grid grid-cols-3 gap-3">
                @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                    <button type="button" @click="press('{{ $digit }}')" class="h-14 rounded-xl bg-gray-100 text-xl font-bold text-gray-900">{{ $digit }}</button>
                @endforeach
                <button type="button" @click="pin = ''" class="h-14 rounded-xl bg-gray-100 text-sm font-bold text-gray-900">Clear</button>
                <button type="button" @click="press('0')" class="h-14 rounded-xl bg-gray-100 text-xl font-bold text-gray-900">0</button>
                <button type="button" @click="pinFor = null" class="h-14 rounded-xl bg-gray-100 text-sm font-bold text-gray-900">Close</button>
            </div>
        </div>
    </div>

    <style>@keyframes hmsFlash { 0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); } 50% { box-shadow: 0 0 0 6px rgba(245, 158, 11, .55); border-radius: 1rem; } }</style>
</div>
