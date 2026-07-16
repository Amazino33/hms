@props([
    'model',              // Alpine expression string this field reads/writes
    'min' => 0,
    'max' => 'null',      // Alpine expression string, 'null' = unbounded
    'integer' => false,
    'currency' => false,  // ₦ live thousands-separator formatting, both on the tap-target and inside the pad
    'label' => null,
])
{{--
    Tap-to-open custom numeric pad, standalone (no +/- stepper) — this is
    what a currency/amount field (declared cash, cash drops, repayments) is:
    stepping a ₦45,000 figure by 1 makes no sense the way stepping a bottle
    count does. mobile.stepper composes this same component and adds the
    +/- buttons around it for quantity fields. Formatting is display-only —
    $model always holds the raw number, never a formatted string.
--}}
<div x-data="{
        padOpen: false,
        pad: '',
        openPad() {
            const v = ({{ $model }})
            this.pad = (v !== '' && v !== null && v !== undefined) ? String(v) : ''
            this.padOpen = true
        },
        padDigit(d) {
            if (d === '.' && this.pad.includes('.')) return
            this.pad = this.pad + d
        },
        padBackspace() { this.pad = this.pad.slice(0, -1) },
        padClear() { this.pad = '' },
        padDone() {
            let v = parseFloat(this.pad || '0')
            if (isNaN(v)) v = 0
            if (v < ({{ $min }})) v = ({{ $min }})
            if (({{ $max }}) !== null && v > ({{ $max }})) v = ({{ $max }})
            {{ $model }} = {{ $integer ? 'Math.round(v)' : 'v' }}
            this.padOpen = false
        },
    }"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    @if($label)
        <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">{{ $label }}</div>
    @endif

    <button type="button" @click="openPad()"
        class="w-full h-12 rounded-xl border-2 border-gray-200 dark:border-gray-700 text-center text-xl font-mono font-bold text-gray-900 dark:text-white touch-manipulation px-3"
        x-text="{{ $currency ? "'₦' + Number({$model} || 0).toLocaleString()" : "String({$model} ?? 0)" }}">
    </button>

    <template x-teleport="body">
        <div x-show="padOpen" x-cloak class="fixed inset-0 z-[70] bg-black/40" @click="padOpen = false" x-transition.opacity></div>
    </template>
    <template x-teleport="body">
        <div x-show="padOpen" x-cloak
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
            class="fixed inset-x-0 bottom-0 z-[71] bg-white dark:bg-gray-900 rounded-t-2xl shadow-2xl border-t border-gray-200 dark:border-gray-700 p-4 max-w-md mx-auto"
            style="padding-bottom: max(1rem, env(safe-area-inset-bottom));">
            <div class="w-10 h-1.5 bg-gray-300 dark:bg-gray-600 rounded-full mx-auto mb-3"></div>
            @if($label)
                <div class="text-center text-xs font-bold uppercase text-gray-400 mb-1">{{ $label }}</div>
            @endif
            <div class="text-center text-3xl font-mono font-bold text-gray-900 dark:text-white mb-4 min-h-[2.5rem]"
                x-text="{{ $currency ? "'₦' + (parseFloat(pad || 0)).toLocaleString()" : "(pad === '' ? '0' : pad)" }}"></div>
            <div class="grid grid-cols-3 gap-2">
                @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                    <button type="button" @click="padDigit('{{ $digit }}')"
                        class="py-4 rounded-xl text-xl font-bold bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white touch-manipulation min-h-[48px]">{{ $digit }}</button>
                @endforeach
                @if(!$integer)
                    <button type="button" @click="padDigit('.')"
                        class="py-4 rounded-xl text-xl font-bold bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white touch-manipulation min-h-[48px]">.</button>
                @else
                    <div></div>
                @endif
                <button type="button" @click="padDigit('0')"
                    class="py-4 rounded-xl text-xl font-bold bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white touch-manipulation min-h-[48px]">0</button>
                <button type="button" @click="padBackspace()"
                    class="py-4 rounded-xl text-lg font-bold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 touch-manipulation min-h-[48px]">&larr;</button>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-3">
                <button type="button" @click="padClear()"
                    class="py-3 rounded-xl font-bold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 touch-manipulation min-h-[48px]">Clear</button>
                <button type="button" @click="padDone()"
                    class="py-3 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation min-h-[48px]">Done</button>
            </div>
        </div>
    </template>
</div>
