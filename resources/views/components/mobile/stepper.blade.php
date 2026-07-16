@props([
    'model',              // Alpine expression string this stepper reads/writes
    'min' => 0,
    'max' => 'null',      // Alpine expression string, 'null' = unbounded
    'step' => 1,
    'integer' => false,
    'label' => null,
])
{{--
    Quantity fields: large -/+ (long-press auto-repeats after 400ms) flanking
    a tap-to-open numeric pad. Composes mobile.numeric-pad for the pad/tap-
    target rather than duplicating it — the +/- buttons and the pad both
    just read/write {{ $model }} directly, no shared state needed between
    them since Alpine expressions are evaluated against the same underlying
    Livewire-bound property either way.

    For a currency/amount field with no natural "step" (declared cash, cash
    drops) use <x-mobile.numeric-pad> directly instead — see its own
    docblock for why stepping a ₦45,000 figure by 1 doesn't make sense the
    way stepping a bottle count does.
--}}
<div x-data="{
        clamp(v) {
            if (isNaN(v)) v = 0
            if (v < ({{ $min }})) v = ({{ $min }})
            if (({{ $max }}) !== null && v > ({{ $max }})) v = ({{ $max }})
            return {{ $integer ? 'Math.round(v)' : 'v' }}
        },
        buzz() { if (navigator.vibrate) navigator.vibrate(10) },
        step(delta) { {{ $model }} = this.clamp((parseFloat({{ $model }}) || 0) + delta); this.buzz() },
        longPress(delta) {
            this.step(delta)
            this._lpTimeout = setTimeout(() => { this._lpInterval = setInterval(() => this.step(delta), 100) }, 400)
        },
        endPress() { clearTimeout(this._lpTimeout); clearInterval(this._lpInterval) },
    }"
    {{ $attributes }}
>
    @if($label)
        <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">{{ $label }}</div>
    @endif
    <div class="flex items-center gap-2">
        <button type="button"
            @mousedown.prevent="longPress(-({{ $step }}))" @mouseup="endPress" @mouseleave="endPress"
            @touchstart.prevent="longPress(-({{ $step }}))" @touchend.prevent="endPress"
            class="shrink-0 w-12 h-12 rounded-xl bg-gray-200 dark:bg-gray-700 active:bg-gray-300 dark:active:bg-gray-600 text-2xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation flex items-center justify-center">&minus;</button>

        <div class="flex-1">
            <x-mobile.numeric-pad :model="$model" :min="$min" :max="$max" :integer="$integer" />
        </div>

        <button type="button"
            @mousedown.prevent="longPress({{ $step }})" @mouseup="endPress" @mouseleave="endPress"
            @touchstart.prevent="longPress({{ $step }})" @touchend.prevent="endPress"
            class="shrink-0 w-12 h-12 rounded-xl bg-gray-200 dark:bg-gray-700 active:bg-gray-300 dark:active:bg-gray-600 text-2xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation flex items-center justify-center">+</button>
    </div>
</div>
