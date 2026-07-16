@props([
    'title' => 'Enter PIN',
    'subtitle' => null,
    'onComplete',   // Alpine expression string evaluated once {length} digits are entered; a
                     // local const `pin` holding the entered digits is in scope, e.g.
                     // onComplete="$wire.declare(pin)" or onComplete="$dispatch('seal-first-pin', pin)"
    'length' => 4,
])
{{--
    The one shared PIN pad every operational surface composes from — single-
    shot (types N digits, fires onComplete). A multi-signature flow (dual-PIN
    seal, declare-then-amend) is built by wiring two instances together via
    onComplete="$dispatch(...)" and an outer x-data step controller, exactly
    like count-session-dual-seal.blade.php already did by hand; see that file
    for the pattern this factors out.

    Deliberately an inline x-data object literal, not a registered
    Alpine.data() component loaded from a separate <script> — every one of
    these renders inside a screen that can arrive via a Livewire DOM morph,
    and a freshly-injected <script> tag has been shown not to reliably
    execute in that situation (see count-session-dual-seal.blade.php's own
    docblock for the incident). This stays a plain Blade partial: one source
    file, reused by @include/<x-mobile.pin-keypad>, each render still an
    inline literal — safe under a morph either way.
--}}
<div x-data="{
        pin: '', pressed: null, submitting: false,
        digit(d) {
            if (this.pin.length >= {{ $length }} || this.submitting) return
            this.pressed = d
            setTimeout(() => { if (this.pressed === d) this.pressed = null }, 150)
            this.pin += d
            if (this.pin.length === {{ $length }}) {
                const pin = this.pin
                this.pin = ''
                this.submitting = true
                Promise.resolve({{ $onComplete }})
                    .then(() => { this.submitting = false })
                    .catch(() => { this.submitting = false })
            }
        },
        backspace() {
            this.pressed = 'back'
            setTimeout(() => { if (this.pressed === 'back') this.pressed = null }, 150)
            this.pin = this.pin.slice(0, -1)
        },
    }"
    {{ $attributes->merge(['class' => 'bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 relative overflow-hidden']) }}>
    <h3 class="text-lg font-bold text-gray-900 dark:text-white text-center mb-1">{{ $title }}</h3>
    @if($subtitle)
        <p class="text-sm text-gray-500 dark:text-gray-400 text-center mb-4">{{ $subtitle }}</p>
    @endif
    <div class="flex justify-center gap-3 mb-5">
        <template x-for="i in {{ $length }}" :key="i">
            <div class="w-5 h-5 rounded-full border-2 border-gray-400 transition-all duration-150"
                :class="i <= pin.length ? 'bg-gray-900 border-gray-900 scale-110' : ''"></div>
        </template>
    </div>
    <div class="grid grid-cols-3 gap-3">
        @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
            <button type="button" @click="digit('{{ $digit }}')"
                :class="pressed === '{{ $digit }}' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
                class="py-4 rounded-lg text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation min-h-[48px]">{{ $digit }}</button>
        @endforeach
        <div></div>
        <button type="button" @click="digit('0')"
            :class="pressed === '0' ? 'bg-amber-400 scale-95' : 'bg-gray-100 dark:bg-gray-800'"
            class="py-4 rounded-lg text-xl font-bold text-gray-900 dark:text-white transition-all duration-100 touch-manipulation min-h-[48px]">0</button>
        <button type="button" @click="backspace"
            :class="pressed === 'back' ? 'bg-red-300 scale-95' : 'bg-red-100 dark:bg-red-900/30'"
            class="py-4 rounded-lg text-lg font-bold text-red-700 dark:text-red-400 transition-all duration-100 touch-manipulation min-h-[48px]">&larr;</button>
    </div>
    <div x-show="submitting" x-cloak x-transition.opacity.duration.100ms
        class="absolute inset-0 bg-white/95 dark:bg-gray-900/95 flex flex-col items-center justify-center gap-3">
        <svg class="animate-spin h-10 w-10 text-amber-500" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <p class="text-sm font-bold text-gray-600 dark:text-gray-300">Confirming…</p>
    </div>
</div>
