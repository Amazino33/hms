@props([
    'options',  // assoc array value => label (PHP array, resolved server-side)
    'model',    // Alpine expression string, e.g. 'paymentMethod' or 'newItem.category_id'
])
{{--
    2-4 options render as a full-width segmented toggle; 5+ render as a
    wrapping chip row — the exact split the spec calls for, decided once
    here so every call site just passes its option list. Selected state is
    filled background + a check icon, never color alone (cheap screens,
    bright bar lighting). String(...) on both sides of the comparison so
    this works whether the bound property/option keys are ints or strings —
    an HTML <select> this replaces would have submitted strings either way.
--}}
@php($segmented = count($options) <= 4)
<div {{ $attributes->merge(['class' => 'flex gap-2 ' . ($segmented ? 'w-full' : 'flex-wrap')]) }}>
    @foreach($options as $value => $optionLabel)
        <button type="button"
            @click="{{ $model }} = '{{ $value }}'; if (navigator.vibrate) navigator.vibrate(10)"
            :class="String({{ $model }}) === '{{ $value }}' ? 'bg-primary-600 border-primary-600 text-white' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300'"
            class="{{ $segmented ? 'flex-1' : '' }} min-h-[48px] px-4 py-2.5 rounded-xl border-2 font-bold text-sm touch-manipulation flex items-center justify-center gap-1.5 transition-colors">
            <svg x-show="String({{ $model }}) === '{{ $value }}'" x-cloak class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/>
            </svg>
            <span class="truncate">{{ $optionLabel }}</span>
        </button>
    @endforeach
</div>
