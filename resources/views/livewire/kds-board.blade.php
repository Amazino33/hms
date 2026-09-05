<?php

use Livewire\Volt\Component;
use App\Models\Order;
use App\Services\KitchenOrderService;
use App\Services\PinAuthService;
use App\Services\SettingsService;
use App\Exceptions\PinLockedException;
use Illuminate\Support\Facades\Auth;

/**
 * The board itself is viewable purely under the registered kiosk device
 * (kiosk.device middleware on the /kds route) — no PIN needed just to look
 * at tickets and timers. Marking anything ready/picked-up requires an
 * active cook, which is exactly a staff_pin login on this device (the same
 * mechanism /kiosk and /staff already use) — there is no separate
 * "active cook" table; Auth::guard('staff_pin')->user() IS the active cook,
 * and every write action re-checks that guard itself, not just a client-
 * side disabled attribute, so an unattributed action is structurally
 * impossible rather than merely hidden.
 */
new class extends Component {
    public bool $showPinPad = false;

    public ?string $errorMessage = null;

    public ?int $lockedUntilTimestamp = null;

    /**
     * Every request (including wire:poll's own AJAX call) must re-assert
     * this — Livewire's follow-up requests don't reliably re-run the
     * staff_pin.auth middleware that would otherwise set it, the same
     * lesson pos.blade.php's own boot() already documents. Unlike
     * pos.blade.php this never redirects away when no PIN session exists —
     * the board itself is meant to stay visible either way.
     */
    public function boot(): void
    {
        if (Auth::guard('staff_pin')->check()) {
            Auth::shouldUse('staff_pin');
        }
    }

    protected function throttleKey(): string
    {
        $kioskDeviceId = session('kiosk_device_id');

        return $kioskDeviceId ? "kds:{$kioskDeviceId}" : 'kds:unscoped:' . request()->ip();
    }

    public function openPinPad(): void
    {
        $this->showPinPad = true;
        $this->errorMessage = null;
        $this->lockedUntilTimestamp = null;
    }

    public function closePinPad(): void
    {
        $this->showPinPad = false;
        $this->errorMessage = null;
    }

    public function submitPin(?string $pin): void
    {
        if (! $pin || strlen($pin) !== PinAuthService::PIN_LENGTH) {
            $this->errorMessage = 'Enter all 4 digits first.';
            return;
        }

        $service = new PinAuthService();

        try {
            $user = $service->attempt($pin, $this->throttleKey());
        } catch (PinLockedException $e) {
            $this->lockedUntilTimestamp = $e->lockedUntilTimestamp;
            $this->errorMessage = $e->getMessage();
            return;
        }

        if (! $user) {
            $this->errorMessage = 'Incorrect PIN.';
            return;
        }

        Auth::guard('staff_pin')->login($user);
        $this->showPinPad = false;
        $this->errorMessage = null;

        // This board signs a cook in and out in place, with no navigation —
        // unlike the idle screen, which redirects into the order screen and
        // re-mounts everything. The announcement board is a sibling
        // component in the kiosk layout, so it has no other way to learn
        // that somebody is now present and may have a notice to read.
        $this->dispatch('staff-pin-changed');
    }

    public function signOutCook(): void
    {
        Auth::guard('staff_pin')->logout();

        // Same reason in reverse: clear the outgoing cook's notices off the
        // screen instead of leaving them up for whoever signs in next.
        $this->dispatch('staff-pin-changed');
    }

    /**
     * Server-side gate every write action re-checks — the disabled button
     * in the view is a courtesy, not the actual boundary.
     */
    protected function requireActiveCook(): ?\App\Models\User
    {
        $cook = Auth::guard('staff_pin')->user();

        if (! $cook) {
            $this->errorMessage = 'Sign in as the active cook before marking anything ready.';
        }

        return $cook;
    }

    public function markReady(int $orderId): void
    {
        $cook = $this->requireActiveCook();

        if (! $cook) {
            return;
        }

        try {
            (new KitchenOrderService())->markReady($orderId, $cook->id);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function markPickedUp(int $orderId): void
    {
        $cook = $this->requireActiveCook();

        if (! $cook) {
            return;
        }

        $order = Order::where('id', $orderId)
            ->where('destination', 'kitchen')
            ->first();

        if (! $order) {
            return;
        }

        if ($order->status !== 'ready') {
            $this->errorMessage = 'Every item on this ticket must be ready before it can be picked up.';
            return;
        }

        if ($order->kds_picked_up_at) {
            return;
        }

        $order->update([
            'kds_picked_up_by' => $cook->id,
            'kds_picked_up_at' => now(),
        ]);
    }

    /**
     * Shared by with() (the normal render) and refreshTiles() (the
     * wire:poll target) — one query, one shape, so the two never drift.
     */
    private function computeBoardData(): array
    {
        $cap = max(1, (int) SettingsService::get('kds_tile_cap', '8'));
        $amberMinutes = (int) SettingsService::get('kds_amber_minutes', '15');
        $redMinutes = (int) SettingsService::get('kds_red_minutes', '30');
        $pollSeconds = max(1, (int) SettingsService::get('kds_poll_seconds', '5'));

        $activeOrders = Order::with(['items', 'table', 'user', 'booking.room', 'guest'])
            ->where('destination', 'kitchen')
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->whereNull('kds_picked_up_at')
            ->oldest()
            ->get();

        $waitingCount = max(0, $activeOrders->count() - $cap);
        $now = now();

        $tickets = $activeOrders->take($cap)->map(function ($order) use ($now) {
            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'origin_label' => $order->origin_label,
                'waiter_name' => $order->user?->name,
                'guest_name' => $order->guest?->name ?? $order->booking?->guest?->name,
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->product_name,
                    'qty' => $i->quantity,
                ])->all(),
                'is_ready' => $order->status === 'ready',
                // (int) abs(...): Carbon's diffInSeconds returns a float
                // with sub-second precision here (the two instants rarely
                // land on the exact same microsecond), and abs() alone
                // guards the sign but leaves that fractional noise —
                // truncate it, this is a whole-seconds display value.
                'elapsed_seconds' => (int) abs($now->diffInSeconds($order->created_at)),
            ];
        })->values()->all();

        return [
            'tickets' => $tickets,
            'waitingCount' => $waitingCount,
            'serverNow' => $now->timestamp,
            'amberSeconds' => $amberMinutes * 60,
            'redSeconds' => $redMinutes * 60,
            'pollSeconds' => $pollSeconds,
        ];
    }

    /**
     * The wire:poll target. A bare wire:poll (no method) still morphs the
     * Blade-rendered tiles correctly on its own — this method exists purely
     * to also dispatch fresh tickets/serverNow to the browser, because
     * Alpine's x-data only ever evaluates its constructor expression once;
     * a plain re-render does not feed new values into an already-running
     * Alpine component. Same dispatch()-then-listen pattern already used
     * for kiosk-idle-screen's print-bill event.
     */
    public function refreshTiles(): void
    {
        $data = $this->computeBoardData();

        $this->dispatch('kds-tick-sync', tickets: $data['tickets'], serverNow: $data['serverNow']);
    }

    public function with(): array
    {
        return array_merge($this->computeBoardData(), [
            'activeCook' => Auth::guard('staff_pin')->user(),
        ]);
    }
}; ?>

<div wire:poll.{{ $pollSeconds }}s="refreshTiles"
    x-data="kdsBoard({
        amberSeconds: {{ $amberSeconds }},
        redSeconds: {{ $redSeconds }},
        showPinPad: @entangle('showPinPad'),
    })"
    x-init="startClock(); resyncFrom(@js($tickets), {{ $serverNow }})"
    x-on:kds-tick-sync.window="resyncFrom($event.detail.tickets, $event.detail.serverNow)"
    x-on:click.window="unlockAudio()"
    class="min-h-screen bg-gray-950 text-white p-4 flex flex-col gap-4"
>
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <h1 class="text-2xl font-bold flex items-center gap-2">
            <span>🔥 Kitchen Display</span>
            @if($waitingCount > 0)
                <span class="text-sm font-bold bg-amber-500 text-black px-3 py-1 rounded-full">+{{ $waitingCount }} waiting</span>
            @endif
        </h1>

        <div class="flex items-center gap-3">
            @if($activeCook)
                <div class="flex items-center gap-2 bg-emerald-900/60 border border-emerald-600 rounded-lg px-3 py-2">
                    <span class="text-xs text-emerald-300">Active cook</span>
                    <span class="font-bold">{{ $activeCook->name }}</span>
                    <button wire:click="signOutCook" class="text-xs underline text-emerald-300">Switch</button>
                </div>
            @else
                <button wire:click="openPinPad" class="px-4 py-2 rounded-lg bg-primary-600 font-bold kiosk-tap">
                    Sign in as active cook
                </button>
            @endif
        </div>
    </div>

    @if($errorMessage)
        <div class="bg-red-900/60 border border-red-600 rounded-lg px-4 py-2 text-sm">{{ $errorMessage }}</div>
    @endif

    {{-- Adaptive grid: cols cap at 4, rows computed from the tile cap. --}}
    @php
        $count = count($tickets);
        $cols = max(1, min($count, 4));
        $rows = $count > 0 ? (int) ceil(min($count, $cols * 100) / $cols) : 1;
    @endphp

    @if($count === 0)
        <div class="flex-1 flex items-center justify-center text-gray-500 text-xl">No active kitchen tickets.</div>
    @else
        <div class="flex-1 grid gap-3" style="grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr)); grid-template-rows: repeat({{ $rows }}, minmax(0, 1fr));">
            @foreach($tickets as $ticket)
                <div wire:key="ticket-{{ $ticket['id'] }}"
                    class="rounded-xl border-2 p-3 flex flex-col overflow-hidden transition-colors"
                    :class="tierClass({{ $ticket['id'] }})"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-bold truncate">{{ $ticket['origin_label'] }}</div>
                            <div class="text-xs text-gray-300 truncate">
                                {{ $ticket['waiter_name'] ?? 'Unknown' }}
                                @if($ticket['guest_name']) · {{ $ticket['guest_name'] }} @endif
                            </div>
                        </div>
                        <div class="shrink-0 px-2 py-1 rounded-full font-mono text-xs font-bold tabular-nums"
                            :class="pillClass({{ $ticket['id'] }})"
                            x-text="formatElapsed({{ $ticket['id'] }})"
                        ></div>
                    </div>

                    <div class="mt-2 flex-1 overflow-y-auto space-y-1" style="font-size: {{ max(11, 16 - count($ticket['items'])) }}px;">
                        @foreach($ticket['items'] as $item)
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded border border-white/40 flex items-center justify-center text-[10px]">{{ $item['qty'] }}</span>
                                <span class="truncate">{{ $item['name'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-2">
                        @if($ticket['is_ready'])
                            <button wire:click="markPickedUp({{ $ticket['id'] }})"
                                @disabled(!$activeCook)
                                class="w-full py-2 rounded-lg bg-emerald-600 disabled:opacity-40 disabled:cursor-not-allowed font-bold text-sm kiosk-tap"
                            >
                                READY — Picked up?
                            </button>
                        @else
                            <button wire:click="markReady({{ $ticket['id'] }})"
                                @disabled(!$activeCook)
                                class="w-full py-2 rounded-lg bg-blue-600 disabled:opacity-40 disabled:cursor-not-allowed font-bold text-sm kiosk-tap"
                            >
                                Mark Ready
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- PIN pad overlay --}}
    <div x-cloak x-show="showPinPad" class="fixed inset-0 bg-black/80 flex items-center justify-center z-50">
        <div class="bg-gray-900 rounded-2xl p-6 w-full max-w-xs" x-data="{ pin: '' }">
            <h2 class="text-lg font-bold mb-4 text-center">Enter your PIN</h2>

            @if($lockedUntilTimestamp)
                <p class="text-red-400 text-sm text-center mb-3">Locked — try again shortly.</p>
            @endif

            <div class="grid grid-cols-3 gap-3 mb-4">
                @foreach ([1,2,3,4,5,6,7,8,9] as $digit)
                    <button type="button" @click="pin = (pin + '{{ $digit }}').slice(0,4)" class="py-4 rounded-lg bg-gray-800 text-xl font-bold kiosk-tap">{{ $digit }}</button>
                @endforeach
                <button type="button" @click="pin = ''" class="py-4 rounded-lg bg-gray-800 text-sm font-bold kiosk-tap">Clear</button>
                <button type="button" @click="pin = (pin + '0').slice(0,4)" class="py-4 rounded-lg bg-gray-800 text-xl font-bold kiosk-tap">0</button>
                <button type="button" @click="pin = pin.slice(0,-1)" class="py-4 rounded-lg bg-gray-800 text-sm font-bold kiosk-tap">⌫</button>
            </div>

            <div class="flex items-center justify-center gap-2 mb-4">
                <template x-for="i in 4" :key="i">
                    <span class="w-3 h-3 rounded-full" :class="pin.length >= i ? 'bg-white' : 'bg-gray-700'"></span>
                </template>
            </div>

            {{-- Called via the $wire proxy from a plain Alpine @click, not
                 a wire:click directive with an inline argument — the same
                 fix kiosk-idle-screen.blade.php already needed: a directive
                 argument doesn't reliably resolve an Alpine-scoped variable,
                 which is exactly how this shipped with a null pin in
                 production the first time. --}}
            <button type="button" @click="$wire.submitPin(pin)" x-bind:disabled="pin.length !== 4" class="w-full py-3 rounded-lg bg-primary-600 font-bold disabled:opacity-40 kiosk-tap">Sign in</button>
            <button type="button" wire:click="closePinPad" class="w-full py-2 mt-2 text-sm text-gray-400">Cancel</button>
        </div>
    </div>
</div>

<script>
    // Deliberately takes only values that stay byte-identical across every
    // Livewire re-render (settings, and the entangled PIN-pad flag) — NOT
    // tickets/serverNow, which differ on every single poll. Baking those
    // into this constructor call was the actual bug behind "the timer only
    // changes on reload": a changed x-data expression string makes Alpine
    // (via Livewire's morph) tear down and rebuild this whole component,
    // discarding the running per-second clock every single poll. Ticket
    // data now arrives exclusively through resyncFrom(), called once for
    // the first paint (x-init below) and again on every poll (the
    // kds-tick-sync listener) — x-data itself never changes, so the
    // component (and its clock) survives every morph untouched.
    function kdsBoard({ amberSeconds, redSeconds, showPinPad }) {
        return {
            tickets: [],
            amberSeconds,
            redSeconds,
            // Entangled with the Livewire property — a real two-way
            // binding, not a value baked in once at render time.
            // openPinPad()/closePinPad() (wire:click) flip the server-side
            // property and the entangle syncs it back here reactively,
            // which a literal boolean interpolated once into x-show cannot
            // do reliably across a Livewire morph (that was the original
            // bug: the button worked server-side, the overlay just never
            // reacted to it).
            showPinPad,
            // Baseline pairs a device-clock reading with the server-computed
            // elapsed_seconds at that same instant — every subsequent tick
            // only ever measures the device-clock DELTA since that pairing,
            // never the device clock's absolute value, so a wrong device
            // clock can't skew the displayed elapsed time, only its own
            // (irrelevant) drift rate between polls.
            baselineDeviceMs: Date.now(),
            // A reactive tick, not Date.now() read directly inside
            // secondsFor() — Alpine only re-evaluates a bound expression
            // when one of the reactive properties it actually reads
            // changes value, so the on-screen timer needs a real reactive
            // property ticking every second, not just a pure-function call
            // to the system clock (which Alpine can't observe changing).
            nowMs: Date.now(),
            clockStarted: false,
            // False until the very first resyncFrom (the initial page
            // load) has run — every ticket already on the board at that
            // moment must never sound the alarm, only ones that genuinely
            // arrive afterward.
            hasLoadedOnce: false,
            audioCtx: null,

            // Guarded so a redundant x-init re-fire (harmless on its own,
            // since x-data's own state is never rebuilt now) can never
            // stack up duplicate intervals all fighting to set the same
            // value.
            startClock() {
                if (this.clockStarted) return;
                this.clockStarted = true;
                setInterval(() => { this.nowMs = Date.now(); }, 1000);
            },

            // Called on every wire:poll tick via the kds-tick-sync event —
            // resyncs both the ticket list (new/removed tickets) and the
            // server-truth elapsed_seconds each carries, so drift can never
            // accumulate between polls. Also the single place new-order
            // arrivals are detected, since every ticket list update
            // (initial paint and every poll) passes through here.
            resyncFrom(freshTickets, freshServerNow) {
                if (this.hasLoadedOnce) {
                    const previousIds = new Set(this.tickets.map(t => t.id));
                    const arrived = freshTickets.some(t => !previousIds.has(t.id));
                    if (arrived) this.playNewOrderChime();
                }
                this.hasLoadedOnce = true;

                this.tickets = freshTickets;
                this.baselineDeviceMs = Date.now();
                this.nowMs = Date.now();
            },

            // Called from a window click listener (see the root element)
            // so the AudioContext is created/resumed off a real user
            // gesture well before it's actually needed — browsers refuse
            // to play audio a page tries to start on its own, and a poll
            // response is never itself a user gesture. A busy kitchen
            // tablet gets tapped constantly, so by the time an order
            // actually needs the chime this has almost always already run.
            unlockAudio() {
                if (!this.audioCtx) {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) return;
                    try {
                        this.audioCtx = new Ctx();
                    } catch (e) {
                        return;
                    }
                }
                if (this.audioCtx.state === 'suspended') {
                    this.audioCtx.resume().catch(() => {});
                }
            },

            // A short two-note chime synthesized with the Web Audio API —
            // no bundled sound file to ship or go missing, and it works
            // identically on every device. Never allowed to throw: a
            // browser without Web Audio support, or one that's still
            // refusing playback, must never break the board itself.
            playNewOrderChime() {
                try {
                    this.unlockAudio();
                    if (!this.audioCtx) return;

                    const ctx = this.audioCtx;
                    const now = ctx.currentTime;

                    [880, 1320].forEach((freq, i) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = freq;
                        osc.connect(gain);
                        gain.connect(ctx.destination);

                        const start = now + i * 0.15;
                        gain.gain.setValueAtTime(0, start);
                        gain.gain.linearRampToValueAtTime(0.4, start + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.3);

                        osc.start(start);
                        osc.stop(start + 0.35);
                    });
                } catch (e) {
                    // Never let a sound failure break the board.
                }
            },

            secondsFor(id) {
                const ticket = this.tickets.find(t => t.id === id);
                if (!ticket) return 0;

                const driftSeconds = Math.floor((this.nowMs - this.baselineDeviceMs) / 1000);
                return ticket.elapsed_seconds + driftSeconds;
            },

            formatElapsed(id) {
                const s = this.secondsFor(id);
                const m = Math.floor(s / 60);
                const sec = s % 60;
                return `${m}:${String(sec).padStart(2, '0')}`;
            },

            tierClass(id) {
                const s = this.secondsFor(id);
                if (s >= this.redSeconds) return 'bg-red-950 border-red-500 animate-pulse';
                if (s >= this.amberSeconds) return 'bg-amber-950 border-amber-500';
                return 'bg-emerald-950 border-emerald-600';
            },

            pillClass(id) {
                const s = this.secondsFor(id);
                if (s >= this.redSeconds) return 'bg-red-600 text-white';
                if (s >= this.amberSeconds) return 'bg-amber-500 text-black';
                return 'bg-emerald-600 text-white';
            },
        };
    }

    document.addEventListener('livewire:navigated', () => {});
</script>
