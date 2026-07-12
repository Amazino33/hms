<x-filament-panels::page>
    @php($session = $this->session)

    @if($session)
        {{-- Collapsed to reclaim vertical space while the counter is actually
             counting — the one-screen layout (Issue 3) needs every pixel it
             can get on a short phone viewport, and this summary is
             redundant noise mid-count anyway (you already know who you are). --}}
        @unless($session->status === 'counting' && $this->iAmCounter())
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Warehouse</div>
                    <div class="font-semibold text-gray-900 dark:text-white">{{ $session->warehouse->name }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Status</div>
                    <div class="font-semibold text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $session->status)) }}</div>
                </div>
                @if($session->outgoingUser)
                    <div>
                        <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Outgoing</div>
                        <div class="font-semibold text-gray-900 dark:text-white">
                            {{ $session->outgoingUser->name }}
                            @if($session->confirmed_by_outgoing_at) <span class="text-green-600">✓ confirmed</span> @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Incoming</div>
                        <div class="font-semibold text-gray-900 dark:text-white">
                            {{ $session->incomingUser->name }}
                            @if($session->confirmed_by_incoming_at) <span class="text-green-600">✓ confirmed</span> @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @endunless

        @if($session->status === 'counting' && $this->isHandoverWithSuccessor() && !$this->iAmCounter())
            <div class="max-w-md mx-auto bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 text-center">
                <p class="text-gray-600 dark:text-gray-300">
                    Waiting for {{ $this->isUnwitnessedSession() ? $session->incomingUser?->name : $session->outgoingUser?->name }}
                    to finish counting{{ $this->isUnwitnessedSession() ? '' : ' and declare' }}.
                </p>
            </div>
        @endif

        @if($session->status === 'counting' && (!$this->isHandoverWithSuccessor() || $this->iAmCounter()))
            {{-- wire:ignore is load-bearing, not decorative: every recordCount()
                 call re-renders the component server-side, and since this
                 element's own x-data attribute embeds @js($this->safeCountItems())
                 directly, Livewire's DOM morph would otherwise see that
                 attribute's string change on every save and treat the whole
                 element as replaced — destroying and re-creating the Alpine
                 component, which resets currentIndex back to 0. That's what
                 sent counters back to product 1 after every single save.
                 wire:ignore freezes this subtree after its first render;
                 everything from here on is pure client-side Alpine state,
                 which is the whole point of the fire-and-forget save design
                 anyway — it was never supposed to depend on a fresh render. --}}
            <div wire:ignore x-data="{
                    items: @js($this->safeCountItems()),
                    isIntegerOnly: {{ $session->type === 'bar_handover' ? 'true' : 'false' }},
                    currentIndex: 0,
                    activeSubLocation: null,
                    saving: false,
                    justSaved: false,
                    pressed: null,
                    finished: false,

                    get current() { return this.items[this.currentIndex] ?? null },
                    get isFirst() { return this.currentIndex === 0 },
                    get isLast() { return this.currentIndex === this.items.length - 1 },
                    get progress() { return this.items.length ? `Product ${this.currentIndex + 1} of ${this.items.length}` : '' },

                    init() {
                        this.activeSubLocation = this.current?.subLocations?.[0] ?? null
                    },

                    selectSlot(loc) {
                        this.activeSubLocation = loc
                    },

                    flash(key) {
                        this.pressed = key
                        setTimeout(() => { if (this.pressed === key) this.pressed = null }, 150)
                    },

                    // Filters physical-keyboard input on the real <input> fields —
                    // digits always, '.' only for a decimal (non-integer-only) session.
                    filterKeydown(e) {
                        const allowedControl = ['Backspace', 'Tab', 'ArrowLeft', 'ArrowRight', 'Delete', 'Enter'];
                        if (allowedControl.includes(e.key)) return;
                        if (/^[0-9]$/.test(e.key)) return;
                        if (e.key === '.' && !this.isIntegerOnly) return;
                        e.preventDefault();
                    },

                    typeDigit(d) {
                        if (!this.activeSubLocation || !this.current) return
                        if (d === '.' && this.isIntegerOnly) return
                        this.flash(d)
                        const existing = (this.current.values[this.activeSubLocation] ?? '').toString()
                        if (d === '.' && existing.includes('.')) return
                        this.current.values[this.activeSubLocation] = existing + d
                    },

                    backspace() {
                        if (!this.activeSubLocation || !this.current) return
                        this.flash('back')
                        const existing = (this.current.values[this.activeSubLocation] ?? '').toString()
                        this.current.values[this.activeSubLocation] = existing.slice(0, -1)
                    },

                    saveCurrent() {
                        const cur = this.current
                        if (!cur) return
                        this.saving = true
                        this.justSaved = false
                        this.$wire.set('subLocationInputs.' + cur.id, cur.values)
                            .then(() => this.$wire.call('recordCount', cur.id))
                            .then(() => { this.justSaved = true })
                            .finally(() => { this.saving = false })
                    },

                    next() {
                        this.saveCurrent()
                        if (!this.isLast) {
                            this.currentIndex++
                            this.activeSubLocation = this.current?.subLocations?.[0] ?? null
                        } else {
                            this.finished = true
                        }
                    },

                    prev() {
                        this.finished = false
                        if (!this.isFirst) {
                            this.currentIndex--
                            this.activeSubLocation = this.current?.subLocations?.[0] ?? null
                        }
                    },

                    enterOnSlot() {
                        const locs = this.current?.subLocations ?? []
                        const idx = locs.indexOf(this.activeSubLocation)
                        if (idx > -1 && idx < locs.length - 1) {
                            this.activeSubLocation = locs[idx + 1]
                        } else {
                            this.next()
                        }
                    },
                }" x-init="init()" class="max-w-md mx-auto flex flex-col">
                <!-- Progress -->
                <div class="flex items-center justify-between mb-2 text-sm shrink-0">
                    <span class="font-bold text-gray-500 dark:text-gray-400" x-text="progress"></span>
                    <span class="text-gray-400" x-show="saving">Saving…</span>
                    <span class="text-green-600 font-bold" x-show="!saving && justSaved" x-transition.opacity.duration.1000ms>Saved ✓</span>
                </div>
                <div class="w-full h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full mb-3 overflow-hidden shrink-0">
                    <div class="h-full bg-primary-500 transition-all duration-200" :style="`width: ${((currentIndex + 1) / items.length) * 100}%`"></div>
                </div>

                <template x-if="!current">
                    <div class="text-center text-gray-500 dark:text-gray-400 py-8">Nothing to count in this session.</div>
                </template>

                {{-- Clear "what next" state once the last product is saved —
                     replaces the product card instead of leaving them
                     wondering why tapping Finish didn't seem to do anything. --}}
                <template x-if="current && finished">
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-emerald-300 dark:border-emerald-700 p-6 text-center">
                        <div class="text-4xl mb-2">✓</div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">All products counted</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Tap the button below this card to review your figures and finish up.
                            Need to fix something first?
                        </p>
                        <button type="button" @click="prev"
                            class="px-4 py-2 rounded-lg bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-bold text-sm touch-manipulation">
                            &larr; Go back and edit
                        </button>
                    </div>
                </template>

                <template x-if="current && !finished">
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 flex flex-col">
                        <!-- Product name -->
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white text-center mb-2 shrink-0" x-text="current.name"></h2>

                        <!-- Sub-location slots: real inputs (keyboard + Tab/Enter on
                             desktop; inputmode="none" suppresses the mobile virtual
                             keyboard so the custom pad below is the only touch input) -->
                        <div class="grid gap-2 mb-2 shrink-0" :class="current.subLocations.length > 1 ? 'grid-cols-' + current.subLocations.length : 'grid-cols-1'">
                            <template x-for="(loc, idx) in current.subLocations" :key="loc">
                                <div class="rounded-xl border-2 p-2 text-center transition-colors"
                                    :class="activeSubLocation === loc ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700'">
                                    <label class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400" x-text="loc"></label>
                                    {{-- inputmode="none" suppresses the mobile virtual keyboard (the
                                         custom pad below is the touch input) without blocking a real
                                         physical keyboard, which ignores inputmode entirely. --}}
                                    <input type="text" inputmode="none" autocomplete="off"
                                        x-model="current.values[loc]"
                                        @focus="selectSlot(loc)"
                                        @keydown="filterKeydown($event)"
                                        @keydown.enter.prevent="enterOnSlot()"
                                        :tabindex="idx + 1"
                                        class="w-full bg-transparent text-center text-2xl font-mono font-bold text-gray-900 dark:text-white mt-1 outline-none border-0 p-0 focus:ring-0">
                                </div>
                            </template>
                        </div>

                        <!-- Number pad (touch) -->
                        <div class="grid grid-cols-3 gap-1.5 mb-2 shrink-0">
                            <template x-for="d in ['1','2','3','4','5','6','7','8','9']" :key="d">
                                <button type="button" @click="typeDigit(d)"
                                    :class="pressed === d ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                    class="py-3 rounded-xl text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">
                                    <span x-text="d"></span>
                                </button>
                            </template>
                            <button type="button" @click="typeDigit('.')" x-show="!isIntegerOnly"
                                :class="pressed === '.' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                class="py-3 rounded-xl text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">.</button>
                            <div x-show="isIntegerOnly"></div>
                            <button type="button" @click="typeDigit('0')"
                                :class="pressed === '0' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                class="py-3 rounded-xl text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">0</button>
                            <button type="button" @click="backspace"
                                :class="pressed === 'back' ? 'bg-red-300' : 'bg-red-100 dark:bg-red-900/30'"
                                class="py-3 rounded-xl text-base font-bold text-red-700 dark:text-red-400 transition-all duration-100 touch-manipulation">&larr;</button>
                        </div>

                        <!-- Prev / Next: sticky so it's always reachable without
                             hunting for it, even on a viewport short enough that
                             the content above still needs a touch of scroll. -->
                        <div class="grid grid-cols-2 gap-3 sticky bottom-0 bg-white dark:bg-gray-900 pt-1 pb-1 shrink-0">
                            <button type="button" @click="prev" :disabled="isFirst"
                                :class="isFirst ? 'opacity-40 cursor-not-allowed bg-gray-100 dark:bg-gray-800' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'"
                                class="py-3 rounded-xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation min-h-[48px]">
                                &larr; Previous
                            </button>
                            <button type="button" @click="next"
                                class="py-3 rounded-xl font-bold text-white touch-manipulation min-h-[48px]"
                                :class="isLast ? 'bg-primary-600 hover:bg-primary-700' : 'bg-gray-800 dark:bg-gray-600 hover:bg-gray-900 dark:hover:bg-gray-500'">
                                <span x-text="isLast ? 'Finish' : 'Next →'"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            @if(!$this->isHandoverWithSuccessor())
                <div class="flex flex-wrap gap-2 mt-4 max-w-md mx-auto">
                    @if($session->outgoing_user_id && !$session->confirmed_by_outgoing_at)
                        <button wire:click="confirmOutgoing" class="px-4 py-2 rounded-lg bg-amber-500 text-white font-bold text-sm">Confirm as Outgoing Custodian</button>
                    @endif
                    @if($session->incoming_user_id && !$session->confirmed_by_incoming_at)
                        <button wire:click="confirmIncoming" class="px-4 py-2 rounded-lg bg-amber-500 text-white font-bold text-sm">Confirm as Incoming Custodian</button>
                    @endif
                    <button wire:click="submitForReview" wire:confirm="Submit this count for manager review? You cannot add more counts afterwards."
                        class="px-4 py-2 rounded-lg bg-primary-600 text-white font-bold text-sm">Submit for Review</button>
                </div>
            @elseif($this->isUnwitnessedSession())
                <div class="max-w-md mx-auto mt-4" x-data="{ show: false }">
                    <button type="button" @click="show = true" x-show="!show"
                        class="w-full py-4 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-lg font-bold touch-manipulation">
                        Finish Counting &rarr; Seal
                    </button>
                    <div x-show="show" x-cloak>
                        @include('filament.pages.partials.count-session-dual-seal', ['firstLabel' => 'Witness — enter your PIN', 'secondLabel' => 'Incoming custodian — enter your PIN'])
                    </div>
                </div>
            @else
                <div class="max-w-md mx-auto mt-4" x-data="{ show: false }">
                    <button type="button" @click="show = true" x-show="!show"
                        class="w-full py-4 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-lg font-bold touch-manipulation">
                        Review &amp; Declare
                    </button>
                    <div x-show="show" x-cloak class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Your Declaration</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Review your own figures below. Once you declare, they're locked — only you can amend them
                            later, and only if the incoming custodian disputes one during their review.
                        </p>
                        <div class="divide-y divide-gray-100 dark:divide-gray-800 mb-4 max-h-64 overflow-y-auto">
                            @foreach($this->safeCountItems() as $summaryItem)
                                <div class="py-2 flex justify-between text-sm">
                                    <span class="text-gray-700 dark:text-gray-300">{{ $summaryItem['name'] }}</span>
                                    <span class="font-mono font-bold text-gray-900 dark:text-white">
                                        {{ collect($summaryItem['values'])->map(fn ($v) => $v === '' ? '0' : $v)->implode(' / ') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <div x-data="{
                                pin: '', pressed: null,
                                digit(d) { if (this.pin.length >= 4) return; this.flash(d); this.pin += d
                                    if (this.pin.length === 4) { const p = this.pin; this.$nextTick(() => { $wire.declare(p).then(() => { show = false }); this.pin = '' }) } },
                                backspace() { this.flash('back'); this.pin = this.pin.slice(0, -1) },
                                flash(key) { this.pressed = key; setTimeout(() => { if (this.pressed === key) this.pressed = null }, 150) },
                            }">
                            <div class="flex justify-center gap-3 mb-5">
                                <template x-for="i in 4" :key="i">
                                    <div class="w-5 h-5 rounded-full border-2 border-gray-400 transition-all duration-150"
                                        :class="i <= pin.length ? 'bg-gray-900 border-gray-900 scale-110' : ''"></div>
                                </template>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                                    <button type="button" @click="digit('{{ $digit }}')"
                                        :class="pressed === '{{ $digit }}' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                        class="py-4 rounded-lg text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">{{ $digit }}</button>
                                @endforeach
                                <div></div>
                                <button type="button" @click="digit('0')"
                                    :class="pressed === '0' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                    class="py-4 rounded-lg text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">0</button>
                                <button type="button" @click="backspace"
                                    :class="pressed === 'back' ? 'bg-red-300 scale-95' : 'bg-red-100 dark:bg-red-900/30'"
                                    class="py-4 rounded-lg text-lg font-bold text-red-700 dark:text-red-400 transition-all duration-100 touch-manipulation">&larr;</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        @if($session->status === 'declared')
            @if($this->needsIncomingBinding())
                {{-- The incoming custodian confirms who they are by PIN before
                     seeing anything — this is the fix for the identity bug:
                     incoming_user_id up to now is only the outgoing
                     custodian's unverified guess from session-open. Whoever
                     types a valid PIN here (matching the right role, and not
                     the outgoing custodian) becomes the bound reviewer,
                     overwriting that guess. Visible to anyone viewing the
                     page — the PIN is the gate, not the logged-in account. --}}
                <div class="max-w-md mx-auto bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white text-center mb-1">Confirm your identity</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 text-center mb-4">
                        Incoming custodian — enter your PIN to begin reviewing {{ $session->outgoingUser?->name }}'s declared count.
                    </p>
                    <div x-data="{
                            pin: '', pressed: null, submitting: false,
                            digit(d) { if (this.pin.length >= 4 || this.submitting) return; this.flash(d); this.pin += d
                                if (this.pin.length === 4) { const p = this.pin; this.submitting = true
                                    this.$nextTick(() => { $wire.bindIncomingReview(p).finally(() => { this.submitting = false; this.pin = '' }) }) } },
                            backspace() { this.flash('back'); this.pin = this.pin.slice(0, -1) },
                            flash(key) { this.pressed = key; setTimeout(() => { if (this.pressed === key) this.pressed = null }, 150) },
                        }">
                        <div class="flex justify-center gap-3 mb-5">
                            <template x-for="i in 4" :key="i">
                                <div class="w-5 h-5 rounded-full border-2 border-gray-400 transition-all duration-150"
                                    :class="i <= pin.length ? 'bg-gray-900 border-gray-900 scale-110' : ''"></div>
                            </template>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                                <button type="button" @click="digit('{{ $digit }}')"
                                    :class="pressed === '{{ $digit }}' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                    class="py-4 rounded-lg text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">{{ $digit }}</button>
                            @endforeach
                            <div></div>
                            <button type="button" @click="digit('0')"
                                :class="pressed === '0' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                class="py-4 rounded-lg text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">0</button>
                            <button type="button" @click="backspace"
                                :class="pressed === 'back' ? 'bg-red-300 scale-95' : 'bg-red-100 dark:bg-red-900/30'"
                                class="py-4 rounded-lg text-lg font-bold text-red-700 dark:text-red-400 transition-all duration-100 touch-manipulation">&larr;</button>
                        </div>
                    </div>
                </div>
            @elseif($this->iAmReviewer())
                {{-- wire:ignore for the same reason as the counting flow above:
                     reviewAccept()/reviewDispute() re-render the component
                     server-side, and this element's x-data embeds
                     @js($this->safeReviewItems()) directly — without this,
                     every Accept/Dispute tap would reset the reviewer back
                     to the first product. --}}
                <div wire:ignore x-data="{
                        items: @js($this->safeReviewItems()),
                        isIntegerOnly: {{ $session->type === 'bar_handover' ? 'true' : 'false' }},
                        currentIndex: 0,
                        activeSubLocation: null,
                        disputing: false,
                        disputeValues: {},

                        get current() { return this.items[this.currentIndex] ?? null },
                        get isFirst() { return this.currentIndex === 0 },
                        get isLast() { return this.currentIndex === this.items.length - 1 },
                        get progress() { return this.items.length ? `Product ${this.currentIndex + 1} of ${this.items.length}` : '' },

                        init() {
                            this.activeSubLocation = this.current?.subLocations?.[0] ?? null
                        },

                        accept() {
                            const cur = this.current
                            if (!cur) return
                            cur.outcome = 'accepted'
                            this.$wire.call('reviewAccept', cur.id)
                            this.goNextIfPossible()
                        },

                        startDispute() {
                            this.disputeValues = {}
                            this.activeSubLocation = this.current?.subLocations?.[0] ?? null
                            this.disputing = true
                        },

                        typeDisputeDigit(d) {
                            if (!this.activeSubLocation) return
                            if (d === '.' && this.isIntegerOnly) return
                            const existing = (this.disputeValues[this.activeSubLocation] ?? '').toString()
                            if (d === '.' && existing.includes('.')) return
                            this.disputeValues[this.activeSubLocation] = existing + d
                        },

                        backspaceDispute() {
                            if (!this.activeSubLocation) return
                            const existing = (this.disputeValues[this.activeSubLocation] ?? '').toString()
                            this.disputeValues[this.activeSubLocation] = existing.slice(0, -1)
                        },

                        submitDispute() {
                            const cur = this.current
                            if (!cur) return
                            cur.outcome = 'disputed'
                            cur.incomingValues = this.disputeValues
                            this.$wire.call('reviewDispute', cur.id, this.disputeValues)
                            this.disputing = false
                            this.goNextIfPossible()
                        },

                        goNextIfPossible() {
                            if (!this.isLast) this.currentIndex++
                        },

                        next() {
                            if (!this.isLast) this.currentIndex++
                        },

                        prev() {
                            if (!this.isFirst) this.currentIndex--
                        },
                    }" x-init="init()" class="max-w-md mx-auto">
                    <div class="flex items-center justify-between mb-3 text-sm">
                        <span class="font-bold text-gray-500 dark:text-gray-400" x-text="progress"></span>
                    </div>
                    <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full mb-5 overflow-hidden">
                        <div class="h-full bg-primary-500 transition-all duration-200" :style="`width: ${((currentIndex + 1) / items.length) * 100}%`"></div>
                    </div>

                    <template x-if="!current">
                        <div class="text-center text-gray-500 dark:text-gray-400 py-8">Nothing to review.</div>
                    </template>

                    <template x-if="current">
                        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-white text-center mb-1" x-text="current.name"></h2>
                            <p class="text-center text-sm text-gray-500 dark:text-gray-400 mb-4">Declared count</p>

                            <div class="grid gap-2 mb-4" :class="current.subLocations.length > 1 ? 'grid-cols-' + current.subLocations.length : 'grid-cols-1'">
                                <template x-for="loc in current.subLocations" :key="loc">
                                    <div class="rounded-xl border-2 border-gray-200 dark:border-gray-700 p-3 text-center">
                                        <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400" x-text="loc"></div>
                                        <div class="text-2xl font-mono font-bold text-gray-900 dark:text-white mt-1" x-text="current.declaredValues[loc] ?? '0'"></div>
                                    </div>
                                </template>
                            </div>

                            <template x-if="!current.outcome || current.outcome === 'accepted'">
                                <div>
                                    <template x-if="!disputing">
                                        <div class="grid grid-cols-2 gap-3 mb-4">
                                            <button type="button" @click="accept" class="py-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold touch-manipulation">Accept</button>
                                            <button type="button" @click="startDispute" class="py-4 rounded-xl bg-red-100 hover:bg-red-200 text-red-700 font-bold touch-manipulation">Dispute</button>
                                        </div>
                                    </template>

                                    <template x-if="disputing">
                                        <div>
                                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Enter what you counted for <span x-text="activeSubLocation"></span>:</p>
                                            <div class="grid gap-2 mb-3" :class="current.subLocations.length > 1 ? 'grid-cols-' + current.subLocations.length : 'grid-cols-1'">
                                                <template x-for="loc in current.subLocations" :key="loc">
                                                    <button type="button" @click="activeSubLocation = loc"
                                                        class="rounded-xl border-2 p-3 text-center touch-manipulation transition-colors"
                                                        :class="activeSubLocation === loc ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700'">
                                                        <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400" x-text="loc"></div>
                                                        <div class="text-2xl font-mono font-bold text-gray-900 dark:text-white mt-1" x-text="disputeValues[loc] === '' || disputeValues[loc] === undefined ? '—' : disputeValues[loc]"></div>
                                                    </button>
                                                </template>
                                            </div>
                                            <div class="grid grid-cols-3 gap-2 mb-2">
                                                <template x-for="d in ['1','2','3','4','5','6','7','8','9']" :key="d">
                                                    <button type="button" @click="typeDisputeDigit(d)"
                                                        class="py-4 rounded-xl text-xl font-bold text-gray-900 dark:text-white bg-gray-100 dark:bg-gray-800 touch-manipulation" x-text="d"></button>
                                                </template>
                                                <button type="button" @click="typeDisputeDigit('.')" x-show="!isIntegerOnly" class="py-4 rounded-xl text-xl font-bold bg-gray-100 dark:bg-gray-800 touch-manipulation">.</button>
                                                <div x-show="isIntegerOnly"></div>
                                                <button type="button" @click="typeDisputeDigit('0')" class="py-4 rounded-xl text-xl font-bold bg-gray-100 dark:bg-gray-800 touch-manipulation">0</button>
                                                <button type="button" @click="backspaceDispute" class="py-4 rounded-xl text-lg font-bold text-red-700 bg-red-100 dark:bg-red-900/30 touch-manipulation">&larr;</button>
                                            </div>
                                            <div class="grid grid-cols-2 gap-3">
                                                <button type="button" @click="disputing = false" class="py-3 rounded-xl font-bold text-gray-700 dark:text-gray-200 bg-gray-200 dark:bg-gray-700 touch-manipulation">Cancel</button>
                                                <button type="button" @click="submitDispute" class="py-3 rounded-xl font-bold text-white bg-red-600 hover:bg-red-700 touch-manipulation">Submit Dispute</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="current.outcome === 'disputed'">
                                <div class="rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 p-3 text-sm text-amber-800 dark:text-amber-300">
                                    Disputed — waiting for both of you to recount this together, then the outgoing
                                    custodian amends it (or you mark it unresolved).
                                </div>
                            </template>

                            <template x-if="current.outcome === 'unresolved'">
                                <div class="rounded-xl bg-gray-100 dark:bg-gray-800 p-3 text-sm text-gray-600 dark:text-gray-300">
                                    Marked unresolved — your count is being used, and a manager has been notified.
                                </div>
                            </template>

                            <div class="grid grid-cols-2 gap-3 mt-4 sticky bottom-0 bg-white dark:bg-gray-900 pt-1">
                                <button type="button" @click="prev" :disabled="isFirst"
                                    :class="isFirst ? 'opacity-40 cursor-not-allowed bg-gray-100 dark:bg-gray-800' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'"
                                    class="py-3 rounded-xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation min-h-[48px]">&larr; Previous</button>
                                <button type="button" @click="next" :disabled="isLast"
                                    :class="isLast ? 'opacity-40 cursor-not-allowed bg-gray-100 dark:bg-gray-800' : 'bg-gray-800 dark:bg-gray-600 hover:bg-gray-900 dark:hover:bg-gray-500'"
                                    class="py-3 rounded-xl font-bold text-white touch-manipulation min-h-[48px]">Next &rarr;</button>
                            </div>
                        </div>
                    </template>
                </div>

            @else
                <div class="max-w-md mx-auto bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 text-center">
                    <p class="text-gray-600 dark:text-gray-300">Waiting for {{ $session->incomingUser?->name }} to review your declared count.</p>
                </div>
            @endif

            {{-- Dispute resolution: shown to whichever of the two can act on
                 a disputed product right now — the outgoing amends (PIN-
                 signed), or the incoming gives up trying to agree. --}}
            @if($this->disputedItems()->isNotEmpty() && ($this->iAmOutgoing() || $this->iAmReviewer()))
                <div class="max-w-md mx-auto mt-4 space-y-3">
                    @foreach($this->disputedItems() as $disputedItem)
                        <div class="bg-white dark:bg-gray-900 rounded-xl border border-amber-300 dark:border-amber-700 p-4" wire:key="dispute-{{ $disputedItem->id }}">
                            <h4 class="font-bold text-gray-900 dark:text-white mb-1">{{ $disputedItem->itemName() }}</h4>
                            <div class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                                Declared:
                                <span class="font-mono">{{ $disputedItem->subCounts->pluck('quantity', 'sub_location')->map(fn ($q) => $this->formatQuantity($q))->implode(', ') }}</span>
                                — Incoming counted:
                                <span class="font-mono">{{ collect($disputedItem->review->incoming_quantities ?? [])->map(fn ($q) => $this->formatQuantity($q))->implode(', ') }}</span>
                            </div>

                            @if($this->iAmOutgoing())
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Recounted together and it's different from what you declared? Enter the real number and confirm with your PIN.</p>
                                {{-- wire:ignore: same reset risk as the counting/
                                     review flows — an unrelated dispute action
                                     elsewhere on the page re-renders this whole
                                     @foreach loop, which would otherwise wipe
                                     whatever this person has half-typed here. --}}
                                <div wire:ignore x-data="{
                                        isIntegerOnly: {{ $session->type === 'bar_handover' ? 'true' : 'false' }},
                                        values: @js($disputedItem->subCounts->pluck('quantity', 'sub_location')->map(fn ($q) => $this->formatQuantity($q))->all()),
                                        pin: '', pressed: null,
                                        digit(d) { if (this.pin.length >= 4) return; this.flash(d); this.pin += d
                                            if (this.pin.length === 4) { const p = this.pin; this.$nextTick(() => { $wire.amendDeclaration({{ $disputedItem->id }}, p, this.values); this.pin = '' }) } },
                                        backspace() { this.flash('back'); this.pin = this.pin.slice(0, -1) },
                                        flash(key) { this.pressed = key; setTimeout(() => { if (this.pressed === key) this.pressed = null }, 150) },
                                    }">
                                    <div class="grid gap-2 mb-2" :class="Object.keys(values).length > 1 ? 'grid-cols-' + Object.keys(values).length : 'grid-cols-1'">
                                        <template x-for="loc in Object.keys(values)" :key="loc">
                                            <div>
                                                <label class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400" x-text="loc"></label>
                                                <input type="number" :step="isIntegerOnly ? '1' : '0.01'" inputmode="numeric" x-model="values[loc]" class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-800 dark:border-gray-600">
                                            </div>
                                        </template>
                                    </div>
                                    <div class="flex justify-center gap-3 mb-3">
                                        <template x-for="i in 4" :key="i">
                                            <div class="w-4 h-4 rounded-full border-2 border-gray-400 transition-all duration-150"
                                                :class="i <= pin.length ? 'bg-gray-900 border-gray-900 scale-110' : ''"></div>
                                        </template>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2">
                                        @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                                            <button type="button" @click="digit('{{ $digit }}')"
                                                :class="pressed === '{{ $digit }}' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                                class="py-3 rounded-lg text-lg font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">{{ $digit }}</button>
                                        @endforeach
                                        <div></div>
                                        <button type="button" @click="digit('0')"
                                            :class="pressed === '0' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                            class="py-3 rounded-lg text-lg font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">0</button>
                                        <button type="button" @click="backspace"
                                            :class="pressed === 'back' ? 'bg-red-300 scale-95' : 'bg-red-100 dark:bg-red-900/30'"
                                            class="py-3 rounded-lg text-base font-bold text-red-700 dark:text-red-400 transition-all duration-100 touch-manipulation">&larr;</button>
                                    </div>
                                </div>
                            @elseif($this->iAmReviewer())
                                <button wire:click="markItemUnresolved({{ $disputedItem->id }})"
                                    wire:confirm="Still disagree after recounting? Your figure will be used, and a manager will be notified."
                                    class="px-3 py-2 rounded-lg bg-gray-700 hover:bg-gray-800 text-white text-xs font-bold">
                                    Still disagree — use my count
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if($this->readyToSeal())
                <div class="max-w-md mx-auto mt-4">
                    @include('filament.pages.partials.count-session-dual-seal', ['firstLabel' => 'Outgoing custodian — enter your PIN', 'secondLabel' => 'Incoming custodian — enter your PIN'])
                </div>
            @endif
        @endif

        @if($session->status === 'pending_review')
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left px-4 py-2">Item</th>
                            <th class="text-left px-4 py-2">Expected</th>
                            <th class="text-left px-4 py-2">Counted</th>
                            <th class="text-left px-4 py-2">Variance</th>
                            <th class="text-left px-4 py-2">Decision</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($session->items as $item)
                            <tr wire:key="review-row-{{ $item->id }}">
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $item->itemName() }}</td>
                                <td class="px-4 py-2 font-mono">{{ $this->formatQuantity($item->adjusted_expected_quantity) }}</td>
                                <td class="px-4 py-2 font-mono">{{ $this->formatQuantity($item->counted_quantity ?? 0) }}</td>
                                <td class="px-4 py-2 font-mono font-bold {{ $item->variance < 0 ? 'text-red-600' : ($item->variance > 0 ? 'text-green-600' : '') }}">
                                    {{ $this->formatQuantity($item->variance) }}
                                </td>
                                <td class="px-4 py-2">
                                    @if($item->decision)
                                        <span class="font-bold">{{ ucwords(str_replace('_', ' ', $item->decision)) }}</span>
                                    @elseif(abs($item->variance) < 0.0001)
                                        <span class="text-gray-400 italic">No variance</span>
                                    @else
                                        <div class="flex gap-2">
                                            <select wire:model="reviewDecisions.{{ $item->id }}" class="border rounded px-2 py-1 text-xs dark:bg-gray-800 dark:border-gray-600">
                                                <option value="">Choose…</option>
                                                <option value="true_up">True-up only</option>
                                                <option value="accountability">True-up + Accountability</option>
                                                <option value="ignored">Ignore</option>
                                            </select>
                                            <input type="text" wire:model="reviewNotes.{{ $item->id }}" placeholder="Notes" class="border rounded px-2 py-1 text-xs w-32 dark:bg-gray-800 dark:border-gray-600">
                                            <button wire:click="decideItem({{ $item->id }})" class="px-2 py-1 bg-primary-500 text-white rounded text-xs font-bold">Save</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <button wire:click="finalizeReview" wire:confirm="Finalize this session? This cannot be undone."
                    class="px-4 py-2 rounded-lg bg-success-600 text-white font-bold text-sm">Finalize Session</button>
            </div>
        @endif

        @if($session->status === 'reviewed')
            @if($this->canStartMyShift())
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                        This was your opening count — no one to hand over from. Start your shift now to begin selling against this stock.
                    </p>
                    <button wire:click="startMyShift" wire:confirm="Start your shift from this count?"
                        class="px-4 py-2 rounded-lg bg-success-600 text-white font-bold text-sm">Start My Shift</button>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-4">
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-white">Final Comparison</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Counted vs. the stock the system expected to be there, per product.</p>
                    </div>
                    <a href="{{ route('handover.pdf', $session->id) }}" target="_blank"
                        class="shrink-0 px-3 py-2 rounded-lg bg-gray-800 dark:bg-gray-700 text-white text-xs font-bold hover:bg-gray-900">
                        Download PDF
                    </a>
                </div>

                @if($session->isHandoverWithSuccessor() && (float) $session->total_shortage_value > 0)
                    <div class="px-4 py-3 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-sm">
                        <span class="font-bold text-red-700 dark:text-red-300">Total shortage: ₦{{ number_format((float) $session->total_shortage_value, 2) }}</span>
                        <span class="text-red-600 dark:text-red-400 text-xs ml-2">— resolved by a manager via Handover Discrepancies</span>
                    </div>
                @endif

                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left px-4 py-2">Item</th>
                            <th class="text-left px-4 py-2">Expected</th>
                            <th class="text-left px-4 py-2">Counted</th>
                            <th class="text-left px-4 py-2">Variance</th>
                            <th class="text-left px-4 py-2">Value (₦)</th>
                            <th class="text-left px-4 py-2">Outcome</th>
                            <th class="text-left px-4 py-2">Decision / Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($session->items as $item)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $item->itemName() }}</td>
                                <td class="px-4 py-2 font-mono text-gray-500 dark:text-gray-400">{{ $this->formatQuantity($item->adjusted_expected_quantity) }}</td>
                                <td class="px-4 py-2 font-mono">{{ $this->formatQuantity($item->counted_quantity) }}</td>
                                <td class="px-4 py-2 font-mono font-bold {{ $item->variance < 0 ? 'text-red-600' : ($item->variance > 0 ? 'text-green-600' : 'text-gray-400') }}">
                                    {{ abs($item->variance) < 0.0001 ? 'None' : $this->formatQuantity($item->variance) }}
                                </td>
                                <td class="px-4 py-2 font-mono">
                                    @if($item->variance < 0 && $item->variance_value !== null)
                                        <span class="text-red-600 font-bold">₦{{ number_format(abs((float) $item->variance_value), 2) }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if($item->review?->outcome === 'unresolved')
                                        <span class="text-amber-600 font-bold text-xs">Unresolved dispute</span>
                                    @elseif($item->review?->outcome === 'disputed')
                                        <span class="text-red-600 font-bold text-xs">Disputed</span>
                                    @elseif($item->review?->outcome === 'accepted')
                                        <span class="text-green-600 text-xs">Accepted</span>
                                    @else
                                        <span class="text-gray-400 text-xs">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-500">
                                    {{ $item->decision ? ucwords(str_replace('_', ' ', $item->decision)) : '—' }}
                                    @if($item->decision_notes)
                                        <div class="text-xs">{{ $item->decision_notes }}</div>
                                    @endif
                                    @if($item->discrepancy)
                                        <div class="text-xs font-bold mt-1
                                            {{ match($item->discrepancy->status) {
                                                'pending_resolution' => 'text-amber-600',
                                                'pending_investigation' => 'text-gray-500',
                                                'debited' => 'text-red-600',
                                                'written_off' => 'text-green-600',
                                                default => 'text-gray-500',
                                            } }}">
                                            {{ match($item->discrepancy->status) {
                                                'pending_resolution' => 'Pending resolution',
                                                'pending_investigation' => 'Pending investigation',
                                                'debited' => 'Debited to outgoing',
                                                'written_off' => 'Written off',
                                                default => $item->discrepancy->status,
                                            } }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
