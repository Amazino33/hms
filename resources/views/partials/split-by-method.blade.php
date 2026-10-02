{{--
    Phase 0E "Split by method" — sits under the fast Mark Paid buttons in
    each of pos.blade.php's three layouts (desktop, phone, kiosk). Settles
    the whole bill in one action across up to three methods; it is NOT
    partial payment over time.

    $instance must be unique per layout on the page: the desktop and phone
    blocks render side by side (CSS decides which shows), and mobile.numeric-pad
    keys its teleported sheet on its model expression — so the amount keys
    carry the instance name to keep each pad's identity distinct.

    Everything here is a courtesy. The server (FastMarkPaidService) re-reads
    the bill, and refuses any set of lines that doesn't add up to it exactly.

    Phase 4: on a guest QR table with "I've paid" claims, the panel opens by
    itself with the claims listed at the top and the lines pre-filled — a
    transfer line for the claims (capped at the bill, payer names as the
    reference) and cash for the rest. The waiter can change everything. An
    amber warning (D12) shows when claims exist but no transfer line does;
    it never blocks.
--}}
@php($i = $instance)
@php($guestHasClaims = $this->guestHasOpenClaims($selectedTableId ?? null))
<div class="mt-3"
    x-data="{
        splitOpen: false,
        busy: false,
        outstanding: 0,
        count: 2,
        methods: ['cash', 'transfer', 'pos'],
        amounts: { {{ $i }}_0: 0, {{ $i }}_1: 0, {{ $i }}_2: 0 },
        refs: ['', '', ''],
        claims: [],
        claimed: 0,
        labels: { cash: 'Cash', pos: 'POS', transfer: 'Transfer' },
        get noTransferWarning() {
            return this.claimed > 0 && !this.methods.slice(0, this.count).some((m, n) => m === 'transfer' && this.amt(n) > 0)
        },
        amt(n) { return Number(this.amounts['{{ $i }}_' + n] || 0) },
        round(v) { return Math.round(v * 100) / 100 },
        get remaining() {
            let sum = 0
            for (let n = 0; n < this.count; n++) sum += this.amt(n)
            return this.round(this.outstanding - sum)
        },
        options(n) {
            const taken = this.methods.slice(0, this.count).filter((m, idx) => idx !== n)
            return ['cash', 'pos', 'transfer'].filter(m => !taken.includes(m))
        },
        fillLast(changed) {
            const last = this.count - 1
            if (changed === last || last === 0) return
            let others = 0
            for (let n = 0; n < last; n++) others += this.amt(n)
            this.amounts['{{ $i }}_' + last] = Math.max(0, this.round(this.outstanding - others))
        },
        async toggle() {
            if (this.splitOpen) { this.splitOpen = false; return }
            this.busy = true
            let prefill = {}
            try {
                prefill = (await $wire.fastPayPrefill()) || {}
            } finally {
                this.busy = false
            }
            this.outstanding = Number(prefill.outstanding) || 0
            this.claims = prefill.claims || []
            this.claimed = Number(prefill.claimed) || 0
            this.count = 2
            this.methods = ['cash', 'transfer', 'pos']
            this.refs = ['', '', '']
            this.amounts['{{ $i }}_0'] = 0
            this.amounts['{{ $i }}_2'] = 0
            this.amounts['{{ $i }}_1'] = this.outstanding
            const lines = prefill.lines || []
            if (lines.length) {
                // Pre-filled from the guests' claims; set the amounts after
                // the methods so the auto-fill of the last line agrees.
                this.count = lines.length
                lines.forEach((l, n) => { this.methods[n] = l.method; this.refs[n] = l.payer_reference || '' })
                if (lines.length === 1) this.methods[1] = 'cash'
                this.$nextTick(() => lines.forEach((l, n) => { this.amounts['{{ $i }}_' + n] = Number(l.amount) || 0 }))
            }
            this.splitOpen = true
        },
        addLine() {
            if (this.count >= 3) return
            this.methods[this.count] = this.options(this.count)[0]
            this.amounts['{{ $i }}_' + this.count] = 0
            this.refs[this.count] = ''
            this.count++
            this.fillLast(0)
        },
        removeLine() {
            if (this.count <= 1) return
            this.count--
            this.amounts['{{ $i }}_' + this.count] = 0
            this.refs[this.count] = ''
            if (this.count === 1) this.amounts['{{ $i }}_0'] = this.outstanding
            else this.fillLast(0)
        },
        async confirm() {
            if (this.busy || this.remaining !== 0 || this.outstanding <= 0) return
            const lines = []
            for (let n = 0; n < this.count; n++) {
                lines.push({
                    method: this.methods[n],
                    amount: this.amt(n),
                    payer_reference: this.methods[n] === 'transfer' ? this.refs[n] : null,
                })
            }
            this.busy = true
            try {
                if (await $wire.markPaidSplit(lines)) this.splitOpen = false
            } finally {
                this.busy = false
            }
        },
    }"
    x-init="
        @if ($guestHasClaims) toggle(); @endif
        $watch('amounts.{{ $i }}_0', () => fillLast(0));
        $watch('amounts.{{ $i }}_1', () => fillLast(1));
        $watch('amounts.{{ $i }}_2', () => fillLast(2));
    ">
    <button type="button" @click="toggle()" :disabled="busy"
        class="w-full h-14 rounded-xl border-2 border-dashed font-bold text-sm touch-manipulation transition-colors"
        :class="splitOpen
            ? 'border-gray-400 text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800'
            : 'border-emerald-500 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20'"
        x-text="splitOpen ? 'Close split by method' : 'Split by method (e.g. part cash, part transfer)'"></button>

    <div x-show="splitOpen" x-cloak class="mt-3 space-y-3">
        <template x-if="claims.length">
            <div class="rounded-xl border-2 border-purple-400 bg-purple-50 dark:bg-purple-900/30 p-3">
                <div class="text-xs font-bold uppercase text-purple-700 dark:text-purple-300 mb-1">Guests say they paid by transfer</div>
                <template x-for="(c, k) in claims" :key="k">
                    <div class="flex justify-between gap-2 text-sm text-gray-800 dark:text-gray-100">
                        <span x-text="c.name + (c.account ? ' → ' + c.account : '')"></span>
                        <span class="font-bold tabular-nums" x-text="'₦' + Number(c.amount).toLocaleString()"></span>
                    </div>
                </template>
                <div class="flex justify-between gap-2 mt-1 pt-1 border-t border-purple-200 dark:border-purple-700 font-bold text-purple-800 dark:text-purple-200">
                    <span>Claimed</span><span class="tabular-nums" x-text="'₦' + claimed.toLocaleString()"></span>
                </div>
            </div>
        </template>
        <div x-show="noTransferWarning" class="rounded-xl border-2 border-amber-500 bg-amber-50 dark:bg-amber-900/30 p-3 text-sm font-semibold text-amber-800 dark:text-amber-300"
            x-text="'Guest claimed ₦' + claimed.toLocaleString() + ' by transfer — no transfer line entered. Continue?'"></div>
        @for ($n = 0; $n < 3; $n++)
            <div x-show="count > {{ $n }}" class="rounded-xl border border-gray-200 dark:border-gray-700 p-3 space-y-2">
                <div class="grid grid-cols-2 gap-2 items-start">
                    <select x-model="methods[{{ $n }}]"
                        class="h-14 w-full rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-bold px-3 touch-manipulation">
                        <template x-for="m in options({{ $n }})" :key="m">
                            <option :value="m" x-text="labels[m]" :selected="m === methods[{{ $n }}]"></option>
                        </template>
                    </select>
                    <x-mobile.numeric-pad model="amounts.{{ $i }}_{{ $n }}" :currency="true" :tall="true" label="Amount" :hide-field-label="true" />
                </div>
                <input type="text" x-show="methods[{{ $n }}] === 'transfer'" x-model="refs[{{ $n }}]" maxlength="120"
                    placeholder="Payer name / reference"
                    class="h-14 w-full rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 touch-manipulation">
            </div>
        @endfor

        <div class="grid grid-cols-2 gap-2">
            <button type="button" @click="addLine()" x-show="count < 3"
                class="h-14 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 font-bold text-sm touch-manipulation">+ Add method</button>
            <button type="button" @click="removeLine()" x-show="count > 1"
                class="h-14 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 font-bold text-sm touch-manipulation">− Remove last</button>
        </div>

        <div class="flex justify-between items-center text-lg font-bold tabular-nums"
            :class="remaining < 0 ? 'text-red-600 dark:text-red-400' : (remaining === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400')">
            <span x-text="remaining < 0 ? 'Over by:' : 'Remaining:'"></span>
            <span x-text="'₦' + Math.abs(remaining).toLocaleString()"></span>
        </div>

        <button type="button" @click="confirm()" :disabled="busy || remaining !== 0 || outstanding <= 0"
            class="w-full h-14 rounded-xl text-white font-bold touch-manipulation transition-colors disabled:cursor-not-allowed"
            :class="remaining < 0 ? 'bg-red-600' : (remaining === 0 && outstanding > 0 ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-emerald-600/40')"
            x-text="busy ? 'Saving…' : ('Confirm · settle ₦' + outstanding.toLocaleString())"></button>
    </div>
</div>
