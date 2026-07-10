{{--
    The dual-PIN seal: two sequential PIN entries (first the outgoing
    custodian or, on the unwitnessed path, the witness; then the incoming
    custodian), each on their own freshly-shuffled keypad. Only once both
    are typed does sealAgreement() get called with both PINs — nothing is
    submitted after the first entry alone.
--}}
<div x-data="{ step: 'first', firstPin: null, submitting: false }"
    class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 relative overflow-hidden">
    <h3 class="text-lg font-bold text-gray-900 dark:text-white text-center mb-1">Seal the Agreement</h3>

    <template x-if="step === 'first'">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400 text-center mb-4">{{ $firstLabel }}</p>
            <div @pin-entered="firstPin = $event.detail; step = 'second'">
                <x-pin-keypad />
            </div>
        </div>
    </template>

    <template x-if="step === 'second'">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400 text-center mb-4">{{ $secondLabel }}</p>
            <div @pin-entered="
                submitting = true
                $wire.sealAgreement(firstPin, $event.detail).then(() => { submitting = false })
            ">
                <x-pin-keypad />
            </div>
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
