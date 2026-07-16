{{--
    The dual-PIN seal: two sequential PIN entries (first the outgoing
    custodian or, on the unwitnessed path, the witness; then the incoming
    custodian). Only once both are typed does sealAgreement() get called
    with both PINs — nothing is submitted after the first entry alone.

    Composed from two <x-mobile.pin-keypad> instances rather than the
    duplicated digit-pad markup this used to have inline. Alpine's scope
    chaining lets each instance's onComplete expression reach up into this
    wrapper's own step/firstPin — step 1 has no server round trip (it's a
    local capture only, matching the original behavior exactly), step 2's
    onComplete directly returns the real $wire.sealAgreement() promise so
    that pad's own spinner stays up for the actual network round trip, not
    just the instant it takes to read the typed digits.
--}}
<div x-data="{ step: 'first', firstPin: null }"
    class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 relative overflow-hidden">
    <h3 class="text-lg font-bold text-gray-900 dark:text-white text-center mb-1">Seal the Agreement</h3>

    <template x-if="step === 'first'">
        <x-mobile.pin-keypad :title="$firstLabel" onComplete="firstPin = pin; step = 'second'" />
    </template>

    <template x-if="step === 'second'">
        <div>
            <x-mobile.pin-keypad :title="$secondLabel"
                onComplete="$wire.sealAgreement(firstPin, pin).then((ok) => { if (!ok) { step = 'first'; firstPin = null } })" />
            <button type="button" @click="step = 'first'; firstPin = null"
                class="w-full mt-3 py-2 text-gray-500 dark:text-gray-400 text-sm font-bold touch-manipulation kiosk-tap">
                &larr; Back, re-enter the first PIN
            </button>
        </div>
    </template>
</div>
