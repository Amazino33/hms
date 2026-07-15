{{--
    The solo count's single-PIN close: one signature, the counter's own —
    confirming her PIN commits the count immediately (stock reconciled,
    discrepancies created for any variance). No second person, no witness.
    Mirrors count-session-dual-seal's inline-x-data-not-a-script-tag
    reasoning: this only ever enters the DOM through a Livewire morph.
--}}
<div x-data="{ pin: '', pressed: null, submitting: false,
        digit(d) { if (this.pin.length >= 4 || this.submitting) return; this.flash(d); this.pin += d
            if (this.pin.length === 4) {
                const p = this.pin; this.pin = ''; this.submitting = true
                $wire.submitSoloCount(p).then((ok) => { this.submitting = false })
            } },
        backspace() { this.flash('back'); this.pin = this.pin.slice(0, -1) },
        flash(key) { this.pressed = key; setTimeout(() => { if (this.pressed === key) this.pressed = null }, 150) },
    }"
    class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 relative overflow-hidden">
    <h3 class="text-lg font-bold text-gray-900 dark:text-white text-center mb-1">Confirm and Submit</h3>
    <p class="text-sm text-gray-500 dark:text-gray-400 text-center mb-4">Enter your PIN to seal this count. This can't be undone.</p>

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

    <div x-show="submitting" x-cloak x-transition.opacity.duration.100ms
        class="absolute inset-0 bg-white/95 dark:bg-gray-900/95 flex flex-col items-center justify-center gap-3">
        <svg class="animate-spin h-10 w-10 text-amber-500" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <p class="text-sm font-bold text-gray-600 dark:text-gray-300">Submitting…</p>
    </div>
</div>
