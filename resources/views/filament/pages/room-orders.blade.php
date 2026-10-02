{{-- Reception "Room Orders" (Phase 5). Polls every 5 s; one chime for anything new. --}}
@php
    $ageClass = fn ($at) => match (true) {
        $at?->lt(now()->subMinutes(10)) => 'bg-red-600 text-white',
        $at?->lt(now()->subMinutes(5)) => 'bg-amber-500 text-black',
        default => 'bg-emerald-600 text-white',
    };
    $naira = fn ($n) => '₦'.number_format((float) $n);
@endphp

<x-filament-panels::page>
<div wire:poll.5s
    x-data="{
        sound: false,
        known: @js($chimeIds),
        approveFor: null, phone: '', optIn: false,
        rejectFor: null, rejectReason: '',
        edit: null, mode: 'remove', qty: 1, reason: 'Out of stock', other: '',
        sendFor: null,
        refuseFor: null, refuseReason: '',
        payFor: null, payAmount: 0, payRef: '',
        init() {
            this.sound = window.hmsChime.enabled();
            setInterval(() => this.sound = window.hmsChime.enabled(), 2000);
        },
        enableSound() { this.sound = window.hmsChime.enable(); setTimeout(() => this.sound = window.hmsChime.enabled(), 300); },
        sync(ids) {
            if (ids.some(id => !this.known.includes(id))) window.hmsChime.play(false);
            this.known = ids;
        },
        openEdit(lineId, mode, maxQty) { this.edit = lineId; this.mode = mode; this.qty = Math.max(1, maxQty - 1); this.reason = 'Out of stock'; this.other = ''; },
        submitEdit() {
            const other = this.reason === 'Other' ? this.other : null;
            this.mode === 'reduce' ? $wire.reduceLine(this.edit, this.qty, this.reason, other) : $wire.removeLine(this.edit, this.reason, other);
            this.edit = null;
        },
        openApprove(id) { this.approveFor = id; this.phone = ''; this.optIn = false; },
        openPay(claim) { this.payFor = claim.id; this.payAmount = claim.amount; this.payRef = claim.name; },
    }">
    @include('partials.guest-chime')
    <span wire:key="room-ids-{{ md5(json_encode($chimeIds)) }}" x-init="sync(@js($chimeIds))" hidden></span>

    <button type="button" x-show="!sound" x-cloak @click="enableSound()"
        class="w-full mb-4 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-gray-100 font-semibold">
        🔔 Tap to enable order sounds
    </button>

    {{-- Calls (blue) and payment claims (purple) --}}
    @if ($calls->isNotEmpty() || $claims->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 mb-6">
            @foreach ($calls as $call)
                <div wire:key="rcall-{{ $call->id }}" class="rounded-2xl p-4 bg-sky-50 dark:bg-sky-900/40 border-2 border-sky-400">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-xl font-black text-gray-900 dark:text-white">{{ $call->placeName() }} · {{ $call->label() }}</div>
                        <span class="text-xs font-bold bg-sky-600 text-white rounded-full px-2 py-1">{{ $call->created_at->diffForHumans(now(), true) }}</span>
                    </div>
                    <button type="button" wire:click="acknowledgeCall({{ $call->id }})" class="mt-3 w-full min-h-[56px] rounded-xl bg-sky-600 text-white font-bold text-lg">On it</button>
                </div>
            @endforeach

            @foreach ($claims as $claim)
                <div wire:key="rclaim-{{ $claim->id }}" class="rounded-2xl p-4 bg-purple-50 dark:bg-purple-900/40 border-2 border-purple-400">
                    <div class="text-lg font-bold text-gray-900 dark:text-white">
                        Room {{ $claim->stay?->room?->number }} · {{ $claim->payer_name }} says they paid {{ $naira($claim->amount) }}{{ $claim->transferAccount ? ' → '.$claim->transferAccount->bank_name : '' }}
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-3">
                        <button type="button" @click="openPay(@js(['id' => $claim->id, 'amount' => (float) $claim->amount, 'name' => $claim->payer_name]))"
                            class="min-h-[52px] rounded-xl bg-purple-600 text-white font-bold">Open folio payment</button>
                        <button type="button" wire:click="claimNotReceived({{ $claim->id }})" wire:confirm="Mark this transfer as not received?"
                            class="min-h-[52px] rounded-xl border-2 border-purple-400 text-purple-700 dark:text-purple-300 font-bold">Not received</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- 1. New orders --}}
        <section class="space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">New orders <span class="text-sm text-gray-500">({{ $newOrders->count() }})</span></h3>
            @forelse ($newOrders as $request)
                <div wire:key="rnew-{{ $request->id }}" class="rounded-2xl border-2 border-amber-400 bg-white dark:bg-gray-800 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-3xl font-black text-gray-900 dark:text-white">Room {{ $request->room?->number }}</div>
                        <span class="text-xs font-bold rounded-full px-2 py-1 {{ $ageClass($request->submitted_at) }}">{{ $request->submitted_at?->diffForHumans(now(), true) }}</span>
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $request->stay?->guest?->name }}@if ($request->stay?->guest?->phone) · {{ $request->stay->guest->phone }}@endif · {{ $request->ref }}
                    </div>
                    <div class="flex flex-wrap gap-2 mt-2">
                        @if ($request->channel === 'whatsapp')
                            <span class="text-xs font-bold bg-green-600 text-white rounded-full px-2 py-1">WhatsApp</span>
                        @else
                            <span class="text-xs font-bold bg-red-600 text-white rounded-full px-2 py-1">No WhatsApp — call the room</span>
                        @endif
                        @if ($request->first_from_device)
                            <span class="text-xs font-bold bg-amber-400 text-black rounded-full px-2 py-1">New phone</span>
                        @endif
                    </div>

                    <ul class="mt-3 space-y-2">
                        @foreach ($request->items->where('status', 'pending') as $line)
                            <li wire:key="rline-{{ $line->id }}" class="flex items-start gap-2">
                                <span class="font-black text-lg text-gray-900 dark:text-white w-10">{{ $line->finalQuantity() }}×</span>
                                <span class="flex-1 font-semibold text-gray-800 dark:text-gray-100 leading-tight">
                                    {{ $line->name_snapshot }}
                                    @if ($line->quantity_final)<span class="text-xs text-gray-500">(asked {{ $line->quantity_requested }})</span>@endif
                                    @if ($line->chips || $line->note)
                                        <span class="block font-extrabold text-red-700 dark:text-yellow-300">{{ implode(' · ', $line->chips ?? []) }} @if ($line->note)“{{ $line->note }}”@endif</span>
                                    @endif
                                </span>
                                @if ($line->finalQuantity() > 1)
                                    <button type="button" @click="openEdit({{ $line->id }}, 'reduce', {{ $line->finalQuantity() }})" class="w-11 h-11 rounded-lg bg-gray-100 dark:bg-gray-700 font-black text-lg" title="Fewer">−</button>
                                @endif
                                <button type="button" @click="openEdit({{ $line->id }}, 'remove', 1)" class="w-11 h-11 rounded-lg bg-gray-100 dark:bg-gray-700 font-black text-red-600" title="Remove">✕</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-2 font-bold text-amber-600">{{ $naira($request->items->where('status', 'pending')->sum(fn ($l) => $l->lineTotal())) }}</div>

                    <div class="grid grid-cols-3 gap-2 mt-3">
                        <button type="button" @click="openApprove({{ $request->id }})" class="col-span-2 min-h-[56px] rounded-xl bg-emerald-600 text-white font-black text-lg">Approve</button>
                        <button type="button" @click="rejectFor = {{ $request->id }}; rejectReason = ''" class="min-h-[56px] rounded-xl border-2 border-red-500 text-red-600 font-bold">Reject</button>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No new room orders.</p>
            @endforelse
        </section>

        {{-- 2. To deliver --}}
        <section class="space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">To deliver <span class="text-sm text-gray-500">({{ $toDeliver->count() }})</span></h3>
            @forelse ($toDeliver as $requestId => $lines)
                @php $request = $lines->first()->request; @endphp
                <div wire:key="rdel-{{ $requestId }}" class="rounded-2xl border-2 border-emerald-400 bg-white dark:bg-gray-800 p-4">
                    <div class="text-2xl font-black text-gray-900 dark:text-white">Room {{ $request?->room?->number }}</div>
                    <div class="text-sm text-gray-500">{{ $request?->ref }} · ready</div>
                    <ul class="mt-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                        @foreach ($lines as $line)
                            <li>{{ $line->finalQuantity() }}× {{ $line->name_snapshot }}</li>
                        @endforeach
                    </ul>
                    <button type="button" @click="sendFor = @js($lines->pluck('id')->values())" class="mt-3 w-full min-h-[56px] rounded-xl bg-emerald-600 text-white font-bold text-lg">Send with…</button>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nothing waiting to go up.</p>
            @endforelse
        </section>

        {{-- 3. Out for delivery --}}
        <section class="space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Out for delivery <span class="text-sm text-gray-500">({{ $outForDelivery->count() }})</span></h3>
            @forelse ($outForDelivery as $key => $lines)
                @php $request = $lines->first()->request; $first = $lines->first(); @endphp
                <div wire:key="rout-{{ $key }}" class="rounded-2xl border-2 border-sky-400 bg-white dark:bg-gray-800 p-4">
                    <div class="text-2xl font-black text-gray-900 dark:text-white">Room {{ $request?->room?->number }}</div>
                    <div class="text-sm text-gray-500">{{ $request?->ref }} · {{ $first->porter?->name ?? 'Reception' }} · out {{ $first->dispatched_at?->diffForHumans(now(), true) }}</div>
                    <ul class="mt-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                        @foreach ($lines as $line)
                            <li>{{ $line->finalQuantity() }}× {{ $line->name_snapshot }}</li>
                        @endforeach
                    </ul>
                    <div class="grid grid-cols-2 gap-2 mt-3">
                        <button type="button" wire:click="markDelivered(@js($lines->pluck('id')->values()))" class="min-h-[56px] rounded-xl bg-emerald-600 text-white font-bold text-lg">Delivered</button>
                        <button type="button" @click="refuseFor = @js($lines->pluck('id')->values()); refuseReason = ''" class="min-h-[56px] rounded-xl bg-red-600 text-white font-bold text-lg">Refused</button>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nothing out right now.</p>
            @endforelse
        </section>
    </div>

    {{-- Approve sheet (D27) --}}
    <div x-show="approveFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="approveFor = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Approve this order</h3>
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Guest WhatsApp number (from chat)
                <input type="tel" x-model="phone" placeholder="Optional — e.g. 0801 234 5678" class="mt-1 w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
            </label>
            <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                <input type="checkbox" x-model="optIn" class="w-5 h-5"> Agreed to receive specials
            </label>
            <div class="flex gap-2">
                <button type="button" @click="approveFor = null" class="flex-1 h-12 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold">Back</button>
                <button type="button" @click="$wire.approve(approveFor, phone || null, optIn); approveFor = null" class="flex-1 h-12 rounded-xl bg-emerald-600 text-white font-bold">Approve</button>
            </div>
        </div>
    </div>

    {{-- Reject --}}
    <div x-show="rejectFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="rejectFor = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Reject — the guest sees the reason</h3>
            <input type="text" x-model="rejectReason" maxlength="100" placeholder="Reason (required)" class="w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
            <div class="flex gap-2">
                <button type="button" @click="rejectFor = null" class="flex-1 h-12 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold">Back</button>
                <button type="button" :disabled="!rejectReason.trim()" @click="$wire.reject(rejectFor, rejectReason); rejectFor = null" class="flex-1 h-12 rounded-xl bg-red-600 text-white font-bold disabled:opacity-40">Reject</button>
            </div>
        </div>
    </div>

    {{-- Reduce / remove a line (D29) --}}
    <div x-show="edit !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="edit = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="mode === 'reduce' ? 'Give fewer' : 'Remove this item'"></h3>
            <template x-if="mode === 'reduce'">
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">New quantity
                    <input type="number" min="1" x-model.number="qty" class="mt-1 w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
                </label>
            </template>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($presets as $preset)
                    <button type="button" @click="reason = '{{ $preset }}'" :class="reason === '{{ $preset }}' ? 'bg-amber-400 text-black' : 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200'"
                        class="min-h-[48px] rounded-lg font-semibold text-sm">{{ $preset }}</button>
                @endforeach
            </div>
            <input type="text" x-show="reason === 'Other'" x-model="other" maxlength="100" placeholder="What happened?" class="w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
            <div class="flex gap-2">
                <button type="button" @click="edit = null" class="flex-1 h-12 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold">Back</button>
                <button type="button" @click="submitEdit()" class="flex-1 h-12 rounded-xl bg-red-600 text-white font-bold">Confirm</button>
            </div>
        </div>
    </div>

    {{-- Send with a porter (or Reception) --}}
    <div x-show="sendFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="sendFor = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Send with</h3>
            @foreach ($porters as $porter)
                <button type="button" @click="$wire.dispatchLines(sendFor, {{ $porter->id }}); sendFor = null" class="w-full min-h-[56px] rounded-xl bg-emerald-600 text-white font-bold text-lg">{{ $porter->name }}</button>
            @endforeach
            <button type="button" @click="$wire.dispatchLines(sendFor, null); sendFor = null" class="w-full min-h-[56px] rounded-xl bg-gray-700 text-white font-bold text-lg">Reception</button>
            <button type="button" @click="sendFor = null" class="w-full h-11 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold">Cancel</button>
        </div>
    </div>

    {{-- Refused (D26) --}}
    <div x-show="refuseFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="refuseFor = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Guest refused the delivery</h3>
            <p class="text-sm text-gray-500">Nothing comes off the bill now — a manager decides.</p>
            <input type="text" x-model="refuseReason" maxlength="200" placeholder="Why? (required)" class="w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
            <div class="flex gap-2">
                <button type="button" @click="refuseFor = null" class="flex-1 h-12 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold">Back</button>
                <button type="button" :disabled="!refuseReason.trim()" @click="$wire.markRefused(refuseFor, refuseReason); refuseFor = null" class="flex-1 h-12 rounded-xl bg-red-600 text-white font-bold disabled:opacity-40">Record refusal</button>
            </div>
        </div>
    </div>

    {{-- Open folio payment, pre-filled from the claim --}}
    <div x-show="payFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="payFor = null">
        <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Record folio payment — transfer</h3>
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Amount (₦)
                <input type="number" min="1" step="0.01" x-model.number="payAmount" class="mt-1 w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
            </label>
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Payer name / reference
                <input type="text" x-model="payRef" maxlength="120" class="mt-1 w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
            </label>
            <p class="text-xs text-gray-500">Check the transfer arrived first. It is saved as a transfer awaiting a manager's verification, like any folio transfer.</p>
            <div class="flex gap-2">
                <button type="button" @click="payFor = null" class="flex-1 h-12 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold">Back</button>
                <button type="button" :disabled="!(payAmount > 0)" @click="$wire.settleClaim(payFor, payAmount, payRef); payFor = null" class="flex-1 h-12 rounded-xl bg-purple-600 text-white font-bold disabled:opacity-40">Save payment</button>
            </div>
        </div>
    </div>
</div>
</x-filament-panels::page>
