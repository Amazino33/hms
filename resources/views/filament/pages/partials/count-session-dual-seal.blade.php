{{--
    The dual-PIN seal: two sequential PIN entries (first the outgoing
    custodian or, on the unwitnessed path, the witness; then the incoming
    custodian). Only once both are typed does sealAgreement() get called
    with both PINs — nothing is submitted after the first entry alone.

    Inline x-data (not a named JS function loaded via a separate <script>
    tag) deliberately — this whole screen only ever enters the DOM through
    a Livewire morph, and a separate script tag injected that way doesn't
    reliably execute, which is exactly what broke the kiosk login PIN pad
    when it briefly shared a component built the same way. An inline object
    literal has nothing to load; Alpine evaluates it directly from the
    x-data attribute.
--}}
<div x-data="{ step: 'first', firstPin: null, submitting: false }"
    class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 relative overflow-hidden">
    <h3 class="text-lg font-bold text-gray-900 dark:text-white text-center mb-1">Seal the Agreement</h3>

    <template x-if="step === 'first'">
        <div x-data="{
                pin: '', pressed: null,
                digit(d) { if (this.pin.length >= 4) return; this.flash(d); this.pin += d
                    if (this.pin.length === 4) { const p = this.pin; this.$nextTick(() => { $dispatch('seal-first-pin', p); this.pin = '' }) } },
                backspace() { this.flash('back'); this.pin = this.pin.slice(0, -1) },
                flash(key) { this.pressed = key; setTimeout(() => { if (this.pressed === key) this.pressed = null }, 150) },
            }"
            @seal-first-pin="firstPin = $event.detail; step = 'second'">
            <p class="text-sm text-gray-500 dark:text-gray-400 text-center mb-4">{{ $firstLabel }}</p>
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
    </template>

    <template x-if="step === 'second'">
        <div x-data="{
                pin: '', pressed: null,
                digit(d) { if (this.pin.length >= 4) return; this.flash(d); this.pin += d
                    if (this.pin.length === 4) { const p = this.pin; this.$nextTick(() => { $dispatch('seal-second-pin', p); this.pin = '' }) } },
                backspace() { this.flash('back'); this.pin = this.pin.slice(0, -1) },
                flash(key) { this.pressed = key; setTimeout(() => { if (this.pressed === key) this.pressed = null }, 150) },
            }"
            @seal-second-pin="
                submitting = true
                $wire.sealAgreement(firstPin, $event.detail).then((ok) => {
                    submitting = false
                    // A wrong first PIN (outgoing/witness) only ever
                    // surfaces here, after the second PIN is typed too —
                    // without this, the screen just kept re-submitting the
                    // same bad first PIN forever with no way back short of
                    // reloading the page. Bounce back to a clean first
                    // entry on any failure, not just a wrong second PIN.
                    if (!ok) { step = 'first'; firstPin = null }
                })
            ">
            <p class="text-sm text-gray-500 dark:text-gray-400 text-center mb-4">{{ $secondLabel }}</p>
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
            <button type="button" @click="step = 'first'; firstPin = null"
                class="w-full mt-3 py-2 text-gray-500 dark:text-gray-400 text-sm font-bold touch-manipulation kiosk-tap">
                &larr; Back, re-enter the first PIN
            </button>
        </div>
    </template>

    <div x-show="submitting" x-cloak x-transition.opacity.duration.100ms
        class="absolute inset-0 bg-white/95 dark:bg-gray-900/95 flex flex-col items-center justify-center gap-3">
        <svg class="animate-spin h-10 w-10 text-amber-500" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <p class="text-sm font-bold text-gray-600 dark:text-gray-300">Sealing…</p>
    </div>
</div>
