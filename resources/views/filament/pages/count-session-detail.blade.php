<x-filament-panels::page>
    @php($session = $this->session)

    @if($session)
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

        @if($session->status === 'counting' && $this->isHandoverWithSuccessor() && !$this->iAmCounter())
            <div class="max-w-md mx-auto bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 text-center">
                <p class="text-gray-600 dark:text-gray-300">
                    Waiting for {{ $this->isUnwitnessedSession() ? $session->incomingUser?->name : $session->outgoingUser?->name }}
                    to finish counting{{ $this->isUnwitnessedSession() ? '' : ' and declare' }}.
                </p>
            </div>
        @endif

        @if($session->status === 'counting' && (!$this->isHandoverWithSuccessor() || $this->iAmCounter()))
            <div x-data="countFlow(@js($this->safeCountItems()))" x-init="init()" class="max-w-md mx-auto">
                <!-- Progress -->
                <div class="flex items-center justify-between mb-3 text-sm">
                    <span class="font-bold text-gray-500 dark:text-gray-400" x-text="progress"></span>
                    <span class="text-gray-400" x-show="saving">Saving…</span>
                    <span class="text-green-600 font-bold" x-show="!saving && justSaved" x-transition.opacity.duration.1000ms>Saved ✓</span>
                </div>
                <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full mb-5 overflow-hidden">
                    <div class="h-full bg-primary-500 transition-all duration-200" :style="`width: ${((currentIndex + 1) / items.length) * 100}%`"></div>
                </div>

                <template x-if="!current">
                    <div class="text-center text-gray-500 dark:text-gray-400 py-8">Nothing to count in this session.</div>
                </template>

                <template x-if="current">
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <!-- Product name -->
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white text-center mb-4" x-text="current.name"></h2>

                        <!-- Sub-location slots: tap to make active, big legible value -->
                        <div class="grid gap-2 mb-4" :class="current.subLocations.length > 1 ? 'grid-cols-' + current.subLocations.length : 'grid-cols-1'">
                            <template x-for="loc in current.subLocations" :key="loc">
                                <button type="button" @click="selectSlot(loc)"
                                    class="rounded-xl border-2 p-3 text-center touch-manipulation transition-colors"
                                    :class="activeSubLocation === loc ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700'">
                                    <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400" x-text="loc"></div>
                                    <div class="text-3xl font-mono font-bold text-gray-900 dark:text-white mt-1"
                                        x-text="current.values[loc] === '' || current.values[loc] === undefined ? '—' : current.values[loc]"></div>
                                </button>
                            </template>
                        </div>

                        <!-- Number pad -->
                        <div class="grid grid-cols-3 gap-2 mb-2">
                            <template x-for="d in ['1','2','3','4','5','6','7','8','9']" :key="d">
                                <button type="button" @click="typeDigit(d)"
                                    :class="pressed === d ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                    class="py-5 rounded-xl text-2xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">
                                    <span x-text="d"></span>
                                </button>
                            </template>
                            <button type="button" @click="typeDigit('.')"
                                :class="pressed === '.' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                class="py-5 rounded-xl text-2xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">.</button>
                            <button type="button" @click="typeDigit('0')"
                                :class="pressed === '0' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                                class="py-5 rounded-xl text-2xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation">0</button>
                            <button type="button" @click="backspace"
                                :class="pressed === 'back' ? 'bg-red-300' : 'bg-red-100 dark:bg-red-900/30'"
                                class="py-5 rounded-xl text-lg font-bold text-red-700 dark:text-red-400 transition-all duration-100 touch-manipulation">&larr;</button>
                        </div>

                        <button type="button" @click="enterOnSlot"
                            class="w-full py-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-lg font-bold touch-manipulation mb-4">
                            Enter
                        </button>

                        <!-- Prev / Next -->
                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" @click="prev" :disabled="isFirst"
                                :class="isFirst ? 'opacity-40 cursor-not-allowed bg-gray-100 dark:bg-gray-800' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'"
                                class="py-3 rounded-xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation">
                                &larr; Previous
                            </button>
                            <button type="button" @click="next"
                                class="py-3 rounded-xl font-bold text-white touch-manipulation"
                                :class="isLast ? 'bg-primary-600 hover:bg-primary-700' : 'bg-gray-800 dark:bg-gray-600 hover:bg-gray-900 dark:hover:bg-gray-500'">
                                <span x-text="isLast ? 'Finish' : 'Next →'"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            @script
            <script>
                function countFlow(items) {
                    return {
                        items: items,
                        currentIndex: 0,
                        activeSubLocation: null,
                        saving: false,
                        justSaved: false,
                        pressed: null,

                        get current() { return this.items[this.currentIndex] ?? null },
                        get isFirst() { return this.currentIndex === 0 },
                        get isLast() { return this.currentIndex === this.items.length - 1 },
                        get progress() { return this.items.length ? `Product ${this.currentIndex + 1} of ${this.items.length}` : '' },

                        init() {
                            this.activeSubLocation = this.current?.subLocations?.[0] ?? null;
                        },

                        selectSlot(loc) {
                            this.activeSubLocation = loc;
                        },

                        flash(key) {
                            this.pressed = key;
                            setTimeout(() => { if (this.pressed === key) this.pressed = null; }, 150);
                        },

                        typeDigit(d) {
                            if (!this.activeSubLocation || !this.current) return;
                            this.flash(d);
                            const existing = (this.current.values[this.activeSubLocation] ?? '').toString();
                            if (d === '.' && existing.includes('.')) return;
                            this.current.values[this.activeSubLocation] = existing + d;
                        },

                        backspace() {
                            if (!this.activeSubLocation || !this.current) return;
                            this.flash('back');
                            const existing = (this.current.values[this.activeSubLocation] ?? '').toString();
                            this.current.values[this.activeSubLocation] = existing.slice(0, -1);
                        },

                        // Fire-and-forget on purpose — the counter must never
                        // wait on the network to move to the next product.
                        // What's already typed lives in `items` (this
                        // component's own state) regardless of when, or
                        // whether, the save finishes.
                        saveCurrent() {
                            const cur = this.current;
                            if (!cur) return;
                            this.saving = true;
                            this.justSaved = false;
                            this.$wire.set('subLocationInputs.' + cur.id, cur.values)
                                .then(() => this.$wire.call('recordCount', cur.id))
                                .then(() => { this.justSaved = true; })
                                .finally(() => { this.saving = false; });
                        },

                        next() {
                            this.saveCurrent();
                            if (!this.isLast) {
                                this.currentIndex++;
                                this.activeSubLocation = this.current?.subLocations?.[0] ?? null;
                            }
                        },

                        prev() {
                            if (!this.isFirst) {
                                this.currentIndex--;
                                this.activeSubLocation = this.current?.subLocations?.[0] ?? null;
                            }
                        },

                        // Enter confirms the active slot and hops to the next
                        // slot on this same product; on the last slot it
                        // behaves like Next.
                        enterOnSlot() {
                            const locs = this.current?.subLocations ?? [];
                            const idx = locs.indexOf(this.activeSubLocation);
                            if (idx > -1 && idx < locs.length - 1) {
                                this.activeSubLocation = locs[idx + 1];
                            } else {
                                this.next();
                            }
                        },
                    };
                }
            </script>
            @endscript

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
            @if($this->iAmReviewer())
                <div x-data="reviewFlow(@js($this->safeReviewItems()))" x-init="init()" class="max-w-md mx-auto">
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
                                                <button type="button" @click="typeDisputeDigit('.')" class="py-4 rounded-xl text-xl font-bold bg-gray-100 dark:bg-gray-800 touch-manipulation">.</button>
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

                            <div class="grid grid-cols-2 gap-3 mt-4">
                                <button type="button" @click="prev" :disabled="isFirst"
                                    :class="isFirst ? 'opacity-40 cursor-not-allowed bg-gray-100 dark:bg-gray-800' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'"
                                    class="py-3 rounded-xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation">&larr; Previous</button>
                                <button type="button" @click="next" :disabled="isLast"
                                    :class="isLast ? 'opacity-40 cursor-not-allowed bg-gray-100 dark:bg-gray-800' : 'bg-gray-800 dark:bg-gray-600 hover:bg-gray-900 dark:hover:bg-gray-500'"
                                    class="py-3 rounded-xl font-bold text-white touch-manipulation">Next &rarr;</button>
                            </div>
                        </div>
                    </template>
                </div>

                @script
                <script>
                    function reviewFlow(items) {
                        return {
                            items: items,
                            currentIndex: 0,
                            activeSubLocation: null,
                            disputing: false,
                            disputeValues: {},

                            get current() { return this.items[this.currentIndex] ?? null },
                            get isFirst() { return this.currentIndex === 0 },
                            get isLast() { return this.currentIndex === this.items.length - 1 },
                            get progress() { return this.items.length ? `Product ${this.currentIndex + 1} of ${this.items.length}` : '' },

                            init() {
                                this.activeSubLocation = this.current?.subLocations?.[0] ?? null;
                            },

                            accept() {
                                const cur = this.current;
                                if (!cur) return;
                                cur.outcome = 'accepted';
                                this.$wire.call('reviewAccept', cur.id);
                                this.goNextIfPossible();
                            },

                            startDispute() {
                                this.disputeValues = {};
                                this.activeSubLocation = this.current?.subLocations?.[0] ?? null;
                                this.disputing = true;
                            },

                            typeDisputeDigit(d) {
                                if (!this.activeSubLocation) return;
                                const existing = (this.disputeValues[this.activeSubLocation] ?? '').toString();
                                if (d === '.' && existing.includes('.')) return;
                                this.disputeValues[this.activeSubLocation] = existing + d;
                            },

                            backspaceDispute() {
                                if (!this.activeSubLocation) return;
                                const existing = (this.disputeValues[this.activeSubLocation] ?? '').toString();
                                this.disputeValues[this.activeSubLocation] = existing.slice(0, -1);
                            },

                            submitDispute() {
                                const cur = this.current;
                                if (!cur) return;
                                cur.outcome = 'disputed';
                                cur.incomingValues = this.disputeValues;
                                this.$wire.call('reviewDispute', cur.id, this.disputeValues);
                                this.disputing = false;
                                this.goNextIfPossible();
                            },

                            goNextIfPossible() {
                                if (!this.isLast) this.currentIndex++;
                            },

                            next() {
                                if (!this.isLast) this.currentIndex++;
                            },

                            prev() {
                                if (!this.isFirst) this.currentIndex--;
                            },
                        };
                    }
                </script>
                @endscript
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
                                <span class="font-mono">{{ $disputedItem->subCounts->pluck('quantity', 'sub_location')->implode(', ') }}</span>
                                — Incoming counted:
                                <span class="font-mono">{{ collect($disputedItem->review->incoming_quantities ?? [])->implode(', ') }}</span>
                            </div>

                            @if($this->iAmOutgoing())
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Recounted together and it's different from what you declared? Enter the real number and confirm with your PIN.</p>
                                <div x-data="{
                                        values: @js($disputedItem->subCounts->pluck('quantity', 'sub_location')->all()),
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
                                                <input type="number" step="0.01" x-model="values[loc]" class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-800 dark:border-gray-600">
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
                                <td class="px-4 py-2 font-mono">{{ $item->adjusted_expected_quantity }}</td>
                                <td class="px-4 py-2 font-mono">{{ $item->counted_quantity ?? 0 }}</td>
                                <td class="px-4 py-2 font-mono font-bold {{ $item->variance < 0 ? 'text-red-600' : ($item->variance > 0 ? 'text-green-600' : '') }}">
                                    {{ $item->variance }}
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

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left px-4 py-2">Item</th>
                            <th class="text-left px-4 py-2">Variance</th>
                            <th class="text-left px-4 py-2">Decision</th>
                            <th class="text-left px-4 py-2">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($session->items as $item)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $item->itemName() }}</td>
                                <td class="px-4 py-2 font-mono">{{ $item->variance }}</td>
                                <td class="px-4 py-2">{{ $item->decision ? ucwords(str_replace('_', ' ', $item->decision)) : '—' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $item->decision_notes }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
