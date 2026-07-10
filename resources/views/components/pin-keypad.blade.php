@props(['errorMessage' => null])

{{--
    Shared by every PIN-entry point on the kiosk (table login, and the
    handover dual-PIN seal screen): masked entry (dots, never digits) and a
    shuffled 0-9 layout, reshuffled fresh every time this mounts, so an
    onlooker watching finger positions over someone's shoulder learns
    nothing. Deliberately owns only digit/pressed/pin state, not submission
    — it dispatches a "pin-entered" event with the completed 4-digit PIN
    and resets, leaving the parent free to decide what happens next
    (a single Livewire call on a kiosk login, or a two-step sequence on the
    dual-PIN seal screen).
--}}
<div x-data="pinKeypad()" x-init="init()">
    <div class="flex justify-center gap-3 mb-5">
        <template x-for="i in 4" :key="i">
            <div class="w-5 h-5 rounded-full border-2 border-gray-400 transition-all duration-150"
                :class="i <= pin.length ? 'bg-gray-900 border-gray-900 scale-110' : ''"></div>
        </template>
    </div>

    @if ($errorMessage)
        <p class="text-center text-red-600 text-sm font-medium mb-3">{{ $errorMessage }}</p>
    @endif

    <div class="grid grid-cols-3 gap-3">
        <template x-for="d in shuffledDigits" :key="d">
            <button type="button" @click="digit(d)"
                :class="pressed === d ? 'bg-amber-400 scale-95' : 'bg-gray-100'"
                class="py-4 rounded-lg text-xl font-bold transition-all duration-100 touch-manipulation"
                x-text="d"></button>
        </template>
        {{ $extraButton ?? '' }}
        <button type="button" @click="backspace"
            :class="pressed === 'back' ? 'bg-red-300 scale-95' : 'bg-red-100'"
            class="py-4 rounded-lg text-lg font-bold text-red-700 transition-all duration-100 touch-manipulation">&larr; Delete</button>
    </div>
</div>

{{--
    This keypad only ever enters the DOM through a Livewire morph — the
    kiosk PIN modal appears after tapping a table, the seal screen after
    a status change — never on the page's initial full load. A plain
    <script> (or @once) injected that way never executes; only @script
    gets Livewire to re-run it after every such update, which is why
    the digit grid was rendering completely empty in production despite
    working in every earlier local check.
--}}
@script
    <script>
        function pinKeypad() {
            return {
                pin: '',
                pressed: null,
                shuffledDigits: [],
                init() {
                    this.shuffledDigits = this.shuffle(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']);
                },
                shuffle(arr) {
                    const a = arr.slice();
                    for (let i = a.length - 1; i > 0; i--) {
                        const j = Math.floor(Math.random() * (i + 1));
                        [a[i], a[j]] = [a[j], a[i]];
                    }
                    return a;
                },
                digit(d) {
                    if (this.pin.length >= 4) return;
                    this.flash(d);
                    this.pin += d;
                    if (this.pin.length === 4) {
                        const completedPin = this.pin;
                        this.$nextTick(() => {
                            this.$dispatch('pin-entered', completedPin);
                            this.pin = '';
                        });
                    }
                },
                backspace() {
                    this.flash('back');
                    this.pin = this.pin.slice(0, -1);
                },
                flash(key) {
                    this.pressed = key;
                    setTimeout(() => {
                        if (this.pressed === key) this.pressed = null;
                    }, 150);
                },
            };
        }
    </script>
@endscript
