@props([
    'model',              // Alpine expression string this field reads/writes
    'min' => 0,
    'max' => 'null',      // Alpine expression string, 'null' = unbounded
    'integer' => false,
    'currency' => false,  // ₦ live thousands-separator formatting, both on the tap-target and inside the pad
    'label' => null,
    'hideFieldLabel' => false, // stepper.blade.php already labels the whole -/pad/+ row — $label still
                               // drives the sheet header (spec: header must reflect the bound field's
                               // identity) but the redundant duplicate above the bare tap-target is skipped.
])
@php
    // A stable per-instance identity for the teleported scrim/sheet pair.
    // Two numeric-pad instances on the same screen (settlement's cash +
    // POS fields, POS's split cash/POS amounts) are otherwise structurally
    // identical DOM subtrees once teleported to <body> — a real-device
    // report found exactly this: a Livewire re-render conflated the two,
    // showing one field's label over the other's pad. wire:key gives
    // Livewire an explicit identity to morph against instead of guessing.
    //
    // MUST be deterministic, not random per render: this component sits
    // on screens that wire:poll (POS re-renders every 10s). A fresh
    // random id on every render made Livewire treat the pad as a brand
    // new element on every single poll tick, orphaning the previous
    // teleported clone in <body> with no valid Alpine scope left —
    // exactly the "padOpen is not defined" crash found live on the
    // kiosk order screen. Hashing the model expression keeps the same
    // field's key identical across renders while still distinguishing
    // different fields on the same screen.
    $padId = 'pad-' . md5($model);
@endphp
{{--
    Tap-to-open custom numeric pad, standalone (no +/- stepper) — this is
    what a currency/amount field (declared cash, cash drops, repayments) is:
    stepping a ₦45,000 figure by 1 makes no sense the way stepping a bottle
    count does. mobile.stepper composes this same component and adds the
    +/- buttons around it for quantity fields. Formatting is display-only —
    $model always holds the raw number, never a formatted string.

    padDirty tracks whether there's a typed-but-not-yet-committed value:
    dismissing via the scrim must NOT discard it (spec requirement), so
    re-opening only re-seeds `pad` from the committed model when nothing
    was pending — otherwise a half-typed figure would silently vanish the
    moment someone taps outside to double-check something on the page.
--}}
<div x-data="{
        padOpen: false,
        padDirty: false,
        pad: '',
        pressed: null,
        openPad() {
            if (!this.padDirty) {
                const v = ({{ $model }})
                this.pad = (v !== '' && v !== null && v !== undefined) ? String(v) : ''
            }
            this.padOpen = true
            window.dispatchEvent(new CustomEvent('hms-pad-open'))
            this.$nextTick(() => {
                this.$el.scrollIntoView({ behavior: 'smooth', block: 'center' })
            })
        },
        padDigit(d) {
            if (d === '.' && this.pad.includes('.')) return
            // Leading-zero cleanup: '0' then '5' becomes '5', not '05' —
            // but '0' then '.' is left alone so '0.05' can still be typed.
            this.pad = (this.pad === '0' && d !== '.') ? d : (this.pad + d)
            this.padDirty = true
            if (navigator.vibrate) navigator.vibrate(10)
        },
        padBackspace() {
            this.pad = this.pad.slice(0, -1)
            this.padDirty = true
        },
        padLongBackspace() {
            this.padBackspace()
            this._bsTimeout = setTimeout(() => { this._bsInterval = setInterval(() => this.padBackspace(), 100) }, 400)
        },
        padEndBackspace() { clearTimeout(this._bsTimeout); clearInterval(this._bsInterval) },
        padClear() { this.pad = ''; this.padDirty = true },
        padDismiss() {
            this.padOpen = false
            window.dispatchEvent(new CustomEvent('hms-pad-close'))
        },
        padDone() {
            let v = parseFloat(this.pad || '0')
            if (isNaN(v)) v = 0
            if (v < ({{ $min }})) v = ({{ $min }})
            if (({{ $max }}) !== null && v > ({{ $max }})) v = ({{ $max }})
            {{ $model }} = {{ $integer ? 'Math.round(v)' : 'v' }}
            this.padDirty = false
            this.padDismiss()
        },
    }"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    @if($label && !$hideFieldLabel)
        <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">{{ $label }}</div>
    @endif

    <button type="button" @click="openPad()"
        class="w-full h-12 rounded-xl border-2 text-center text-xl font-mono font-bold text-gray-900 dark:text-white touch-manipulation px-3 transition-colors"
        :class="padDirty ? 'border-amber-400 dark:border-amber-500' : 'border-gray-200 dark:border-gray-700'"
        x-text="{{ $currency ? "'₦' + Number({$model} || 0).toLocaleString()" : "String({$model} ?? 0)" }}">
    </button>
    <div x-show="padDirty" x-cloak class="text-xs font-bold text-amber-600 dark:text-amber-400 mt-1">Unsaved entry — tap to continue</div>

    <template x-teleport="body">
        <div wire:key="{{ $padId }}-scrim" x-show="padOpen" x-cloak class="fixed inset-0 z-[70] bg-black/40"
            @click="if ($event.target === $event.currentTarget) padDismiss()" x-transition.opacity></div>
    </template>
    <template x-teleport="body">
        <div wire:key="{{ $padId }}-sheet" x-show="padOpen" x-cloak
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
            class="fixed inset-x-0 bottom-0 z-[71] bg-white dark:bg-gray-900 rounded-t-2xl shadow-2xl border-t border-gray-200 dark:border-gray-700 p-4 w-full"
            style="padding-bottom: max(1rem, env(safe-area-inset-bottom));">
            <div class="w-10 h-1.5 bg-gray-300 dark:bg-gray-600 rounded-full mx-auto mb-3"></div>
            <div class="text-center mb-4">
                @if($label)
                    <div class="text-xs font-bold uppercase text-gray-400">{{ $label }}</div>
                @endif
                <div class="text-3xl font-mono font-bold text-gray-900 dark:text-white min-h-[2.5rem]"
                    x-text="{{ $currency ? "'₦' + (parseFloat(pad || 0)).toLocaleString()" : "(pad === '' ? '0' : pad)" }}"></div>
            </div>
            <div class="grid grid-cols-3 gap-2">
                @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                    <button type="button" tabindex="-1" @click="padDigit('{{ $digit }}')"
                        @mousedown="pressed = '{{ $digit }}'" @touchstart="pressed = '{{ $digit }}'"
                        @mouseup="pressed = null" @mouseleave="pressed = null" @touchend="pressed = null"
                        :class="pressed === '{{ $digit }}' ? 'scale-95 bg-gray-200 dark:bg-gray-700' : 'bg-gray-100 dark:bg-gray-800'"
                        class="py-4 rounded-xl text-xl font-bold text-gray-900 dark:text-white touch-manipulation min-h-[48px] transition-transform duration-75">{{ $digit }}</button>
                @endforeach
                @if(!$integer)
                    <button type="button" tabindex="-1" @click="padDigit('.')"
                        class="py-4 rounded-xl text-xl font-bold bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white touch-manipulation min-h-[48px]">.</button>
                @else
                    <div></div>
                @endif
                <button type="button" tabindex="-1" @click="padDigit('0')"
                    @mousedown="pressed = '0'" @touchstart="pressed = '0'"
                    @mouseup="pressed = null" @mouseleave="pressed = null" @touchend="pressed = null"
                    :class="pressed === '0' ? 'scale-95 bg-gray-200 dark:bg-gray-700' : 'bg-gray-100 dark:bg-gray-800'"
                    class="py-4 rounded-xl text-xl font-bold text-gray-900 dark:text-white touch-manipulation min-h-[48px] transition-transform duration-75">0</button>
                <button type="button" tabindex="-1"
                    @mousedown.prevent="padLongBackspace()" @mouseup="padEndBackspace()" @mouseleave="padEndBackspace()"
                    @touchstart.prevent="padLongBackspace()" @touchend.prevent="padEndBackspace()"
                    class="py-4 rounded-xl text-lg font-bold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 touch-manipulation min-h-[48px] active:scale-95 transition-transform duration-75">&larr;</button>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-3">
                <button type="button" tabindex="-1" @click="padClear()"
                    class="py-3 rounded-xl font-bold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 touch-manipulation min-h-[48px]">Clear</button>
                <button type="button" tabindex="-1" @click="padDone()"
                    class="py-3 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation min-h-[48px]">Done</button>
            </div>
        </div>
    </template>
</div>
