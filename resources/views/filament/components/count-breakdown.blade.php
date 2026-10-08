{{--
    Count breakdown: the staff "Count Summary" (cards) and the admin/CEO
    review (table), from one payload built by CountBreakdownViewService.

    Every pop-up's data is already in the payload, so tapping a figure
    never goes back to the server. wire:ignore keeps a Livewire re-render
    from wiping the open tab/modal; a saved explanation is pushed into the
    local copy instead.

    An inline x-data literal on purpose, not a registered Alpine.data()
    component: this can arrive through a Livewire morph right after the
    seal, and an injected script tag doesn't reliably run there (see
    components/mobile/pin-keypad.blade.php).

    Expects: $payload (array), $pdfUrl (?string). The admin table is only
    rendered for an audit payload, so a staff page carries no cost-price
    markup at all, not just no cost data.
--}}
@php($audit = (bool) ($payload['audit'] ?? false))
<div wire:ignore
    x-data="{
        d: @js($payload),
        tab: null,
        onlyVariance: false,
        showOpenOrders: false,
        modal: null,
        noteFor: null,
        noteText: '',
        saving: false,
        sharing: false,
        init() { this.tab = this.d.sections?.[0]?.key ?? null },
        section() { return (this.d.sections || []).find(s => s.key === this.tab) || null },
        lines() {
            // Every product is listed: variance first (biggest ₦ first), then
            // anything that moved, then the untouched ones, each by name.
            let rows = [...(this.section()?.lines || [])]
            if (this.onlyVariance) rows = rows.filter(l => l.variance !== 0)
            return rows.sort((a, b) => (b.variance !== 0) - (a.variance !== 0) || Math.abs(b.value) - Math.abs(a.value) || a.quiet - b.quiet || a.name.localeCompare(b.name))
        },
        q(n) { return n === null || n === undefined ? '—' : Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 }) },
        naira(n) { return n === null || n === undefined ? '—' : '₦' + Math.abs(Number(n)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
        signed(n) { return n < 0 ? '−' + this.q(-n) : (n > 0 ? '+' + this.q(n) : '0') },
        signedNaira(n) { return n === null || n === undefined ? '—' : (n < 0 ? '−' : (n > 0 ? '+' : '')) + this.naira(n) },
        tone(n) { return n < 0 ? 'text-red-600 dark:text-red-400' : (n > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500') },
        pack(line, n) { return line.pack && n ? (n / line.pack.size).toLocaleString('en-US', { maximumFractionDigits: 1 }) + ' ' + line.pack.name : null },
        label(f) {
            return ({ brought_forward: 'Brought forward', transferred: 'Transferred in', returns: 'Returns', other_in: 'Other stock in', sold: this.tab === 'ingredient' ? 'Used' : 'Sold', damages: 'Damages', other_out: 'Other stock out', unrecorded: 'Unrecorded change', expected: 'Expected', counted: 'Counted', variance: 'Variance' })[f]
        },
        open(line, figure) { this.modal = { line, figure } },
        close() { this.modal = null; this.noteFor = null },
        rows(f) { return this.modal?.line?.movements?.[f] || [] },
        figureValue(line, f) { return f === 'variance' ? line.variance : line[f] },
        working(l) {
            let s = this.q(l.brought_forward) + ' B/F + ' + this.q(l.transferred) + ' transferred + ' + this.q(l.returns) + ' returns'
            if (l.other_in) s += ' + ' + this.q(l.other_in) + ' other in'
            s += ' − ' + this.q(l.sold) + (this.tab === 'ingredient' ? ' used' : ' sold') + ' − ' + this.q(l.damages) + ' damaged'
            if (l.other_out) s += ' − ' + this.q(l.other_out) + ' other out'
            if (l.unrecorded) s += (l.unrecorded < 0 ? ' − ' : ' + ') + this.q(Math.abs(l.unrecorded)) + ' unrecorded'
            return s + ' = ' + this.q(l.expected)
        },
        varianceWorking(l) {
            let s = 'counted ' + this.q(l.counted) + ' − expected ' + this.q(l.expected) + ' = '
            if (l.variance === 0) return s + 'exact'
            s += (l.variance < 0 ? 'short ' : 'over ') + this.q(Math.abs(l.variance)) + ' → ' + this.naira(l.value) + ' (sell)'
            @if($audit)
            s += ' / ' + (l.value_cost === null ? 'no cost recorded' : this.naira(l.value_cost) + ' (cost)')
            @endif
            return s
        },
        startNote(line) { this.noteFor = line; this.noteText = '' },
        saveNote() {
            if (this.saving || ! this.noteText.trim()) return
            this.saving = true
            const line = this.noteFor
            this.$wire.addVarianceNote(line.id, this.noteText).then(r => {
                this.saving = false
                if (r) { line.notes.push(r.note); line.can_note = r.can_note; this.noteFor = null; this.noteText = '' }
            }).catch(() => { this.saving = false })
        },
        async share() {
            if (this.sharing) return
            this.sharing = true
            const url = @js($pdfUrl);
            try {
                const res = await fetch(url, { credentials: 'same-origin' })
                if (! res.ok) throw new Error('pdf')
                const blob = await res.blob()
                const name = 'count-' + this.d.session_id + '.pdf'
                const file = new File([blob], name, { type: 'application/pdf' })
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], title: 'Count summary #' + this.d.session_id })
                } else {
                    const a = document.createElement('a')
                    a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove()
                    setTimeout(() => URL.revokeObjectURL(a.href), 2000)
                }
            } catch (e) {
                if (e?.name !== 'AbortError') window.open(url, '_blank')
            }
            this.sharing = false
        },
    }"
    x-on:keydown.escape.window="close()"
    class="mb-4">

    {{-- Header --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-3">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="font-bold text-lg text-gray-900 dark:text-white" x-text="d.audit ? 'Count Breakdown' : 'Count Summary'"></h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <span x-text="d.title"></span><span x-show="d.warehouse"> · <span x-text="d.warehouse"></span></span><span x-show="d.sealed_at"> · sealed <span x-text="d.sealed_at"></span></span>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-show="d.recorded && d.window_from">
                    Covers <span x-text="d.window_from"></span> → <span x-text="d.window_to"></span>
                </p>
            </div>
            @if($pdfUrl)
                <button type="button" x-show="d.recorded" x-on:click="share()" :disabled="sharing"
                    class="shrink-0 min-h-[44px] px-4 rounded-lg bg-gray-900 dark:bg-gray-700 text-white text-sm font-bold hover:bg-black disabled:opacity-60">
                    <span x-text="sharing ? 'Preparing…' : 'Share PDF'"></span>
                </button>
            @endif
        </div>
    </div>

    <template x-if="! d.recorded">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-sm text-gray-600 dark:text-gray-300">
            Breakdown not recorded (before this update).
        </div>
    </template>

    <template x-if="d.recorded">
        <div>
            <div x-show="d.reconstructed_at" class="rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-3 mb-3 text-sm text-amber-900 dark:text-amber-200">
                <span class="font-bold">Rebuilt from records</span> on <span x-text="d.reconstructed_at"></span>.
                This count was sealed before breakdowns were saved at the seal, so it was rebuilt afterwards from the stock records.
                Open orders at handover weren't recorded, and anything changed since the seal is flagged where it shows.
            </div>

            {{-- Open at handover --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 mb-3" x-show="d.open_orders.length">
                <button type="button" class="w-full min-h-[44px] px-4 py-2 flex items-center justify-between text-left" x-on:click="showOpenOrders = ! showOpenOrders">
                    <span class="text-sm font-bold text-gray-900 dark:text-white">Open at handover (<span x-text="d.open_orders.length"></span>)</span>
                    <span class="text-xs text-gray-500" x-text="showOpenOrders ? 'Hide' : 'Show'"></span>
                </button>
                <div x-show="showOpenOrders" class="border-t border-gray-100 dark:border-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                    <p class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">Placed but not yet marked ready when the count locked. Stock for these had usually already been taken off, which can show up as an overage.</p>
                    <template x-for="(o, i) in d.open_orders" :key="i">
                        <div class="px-4 py-2 flex items-center justify-between gap-3 text-sm">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-white truncate" x-text="o.item"></div>
                                <div class="text-xs text-gray-500" x-text="[o.order, o.waiter, o.placed_at].filter(Boolean).join(' · ')"></div>
                            </div>
                            <div class="font-mono tabular-nums" x-text="'×' + q(o.quantity)"></div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Section tabs --}}
            <div class="flex items-center gap-2 mb-3 flex-wrap">
                <template x-for="s in d.sections" :key="s.key">
                    <button type="button" x-on:click="tab = s.key"
                        class="min-h-[44px] px-4 rounded-lg text-sm font-bold border"
                        :class="tab === s.key ? 'bg-red-600 border-red-600 text-white' : 'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200'"
                        x-text="s.label"></button>
                </template>
                <label x-show="d.audit" class="ml-auto min-h-[44px] inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 cursor-pointer">
                    <input type="checkbox" x-model="onlyVariance" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                    Show only items with variance
                </label>
            </div>

            <template x-if="! d.sections.length">
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-sm text-gray-500">No items on this count.</div>
            </template>

            {{-- ───────── Staff: one card per item ───────── --}}
            <template x-if="! d.audit && section()">
                <div class="space-y-3 mb-3">
                    <template x-for="line in lines()" :key="line.id">
                        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-bold text-gray-900 dark:text-white" x-text="line.name"></div>
                                    <div class="text-xs text-gray-500" x-show="line.unit" x-text="'per ' + line.unit"></div>
                                </div>
                                <button type="button" x-on:click="open(line, 'variance')" class="text-right min-h-[44px] px-1 -mr-1 rounded hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <div class="text-2xl font-black tabular-nums underline decoration-dotted underline-offset-4" :class="tone(line.variance)" x-text="signed(line.variance)"></div>
                                    <div class="text-xs font-bold tabular-nums" :class="tone(line.variance)" x-show="line.variance !== 0" x-text="(line.variance < 0 ? 'short ' : 'over ') + naira(line.value)"></div>
                                    <div class="text-xs text-gray-400" x-show="line.variance === 0">exact</div>
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3">
                                <template x-for="f in ['brought_forward', 'transferred', 'returns', 'other_in', 'sold', 'damages', 'other_out', 'unrecorded', 'expected', 'counted']" :key="f">
                                    <button type="button" x-show="! ['other_in', 'other_out', 'unrecorded'].includes(f) || line[f] !== 0"
                                        x-on:click="open(line, f)"
                                        class="min-h-[44px] px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800 text-left hover:bg-gray-100 dark:hover:bg-gray-700">
                                        <div class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="label(f)"></div>
                                        <div class="font-mono tabular-nums font-bold text-gray-900 dark:text-white underline decoration-dotted underline-offset-4">
                                            <span x-text="f === 'unrecorded' ? signed(line[f]) : q(line[f])"></span>
                                            <span class="text-xs font-normal text-gray-500" x-show="f === 'sold' && line.sales_amount !== null" x-text="' · ' + naira(line.sales_amount)"></span>
                                        </div>
                                        <div class="text-[11px] text-gray-400" x-show="['transferred', 'expected', 'counted'].includes(f) && pack(line, line[f])" x-text="pack(line, line[f])"></div>
                                    </button>
                                </template>
                            </div>
                            <div class="flex items-center gap-2 mt-3" x-show="line.variance !== 0">
                                <button type="button" x-show="line.can_note" x-on:click="startNote(line)"
                                    class="min-h-[44px] px-4 rounded-lg border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-bold">Add explanation</button>
                                <span class="text-xs text-gray-500" x-show="line.notes.length" x-text="line.notes.length + (line.notes.length === 1 ? ' explanation' : ' explanations')"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- ───────── Admin / CEO: table ───────── --}}
            @if($audit)
            <template x-if="d.audit && section()">
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm whitespace-nowrap">
                            <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-600 dark:text-gray-300">
                                <tr>
                                    <th class="text-left px-3 py-2 sticky left-0 bg-gray-50 dark:bg-gray-800">Item</th>
                                    <th class="text-right px-3 py-2">B/F</th>
                                    <th class="text-right px-3 py-2">Transferred</th>
                                    <th class="text-right px-3 py-2">Returns</th>
                                    <th class="text-right px-3 py-2">Available</th>
                                    <th class="text-right px-3 py-2" x-text="label('sold')"></th>
                                    <th class="text-right px-3 py-2">Sales ₦</th>
                                    <th class="text-right px-3 py-2">Damages</th>
                                    <th class="text-right px-3 py-2">Expected</th>
                                    <th class="text-right px-3 py-2">Counted</th>
                                    <th class="text-right px-3 py-2">Variance</th>
                                    <th class="text-right px-3 py-2">Variance ₦ (sell)</th>
                                    <th class="text-right px-3 py-2">Variance ₦ (cost)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <template x-for="line in lines()" :key="line.id">
                                    <tr :class="line.quiet ? 'text-gray-500 dark:text-gray-400' : ''">
                                        <td class="px-3 py-2 sticky left-0 bg-white dark:bg-gray-900">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium" :class="line.quiet ? '' : 'text-gray-900 dark:text-white'" x-text="line.name"></span>
                                                <span x-show="line.repeat" class="px-1.5 py-0.5 rounded bg-red-600 text-white text-[10px] font-bold" x-text="line.repeat ? 'Short ' + line.repeat.short + ' of last ' + line.repeat.of : ''"></span>
                                                <button type="button" x-show="line.notes.length" x-on:click="open(line, 'variance')" class="text-xs text-gray-500 hover:text-gray-900" :title="line.notes.length + ' explanation(s)'">
                                                    <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" class="h-4 w-4 inline" />
                                                </button>
                                            </div>
                                            <div class="flex gap-2 text-[11px] text-gray-500">
                                                <template x-for="f in ['other_in', 'other_out', 'unrecorded']" :key="f">
                                                    <button type="button" x-show="line[f] !== 0" x-on:click="open(line, f)" class="underline decoration-dotted" x-text="label(f) + ' ' + (f === 'unrecorded' ? signed(line[f]) : q(line[f]))"></button>
                                                </template>
                                            </div>
                                        </td>
                                        <template x-for="f in ['brought_forward', 'transferred', 'returns', 'available', 'sold']" :key="f">
                                            <td class="px-3 py-2 text-right font-mono tabular-nums">
                                                <button type="button" x-show="f !== 'available'" x-on:click="open(line, f)" class="min-h-[32px] underline decoration-dotted underline-offset-4" x-text="q(line[f])"></button>
                                                <button type="button" x-show="f === 'available'" x-on:click="open(line, 'expected')" class="min-h-[32px] underline decoration-dotted underline-offset-4" x-text="q(line[f])"></button>
                                                <span x-show="f === 'sold' && line.slow_count" class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 text-[10px] font-bold" x-text="line.slow_count + ' slow'"></span>
                                            </td>
                                        </template>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums">
                                            <button type="button" x-on:click="open(line, 'sold')" class="underline decoration-dotted underline-offset-4" x-text="line.sales_amount === null ? '—' : naira(line.sales_amount)"></button>
                                        </td>
                                        <template x-for="f in ['damages', 'expected', 'counted']" :key="f">
                                            <td class="px-3 py-2 text-right font-mono tabular-nums">
                                                <button type="button" x-on:click="open(line, f)" class="min-h-[32px] underline decoration-dotted underline-offset-4" x-text="q(line[f])"></button>
                                            </td>
                                        </template>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums font-bold" :class="tone(line.variance)">
                                            <button type="button" x-on:click="open(line, 'variance')" class="underline decoration-dotted underline-offset-4" x-text="signed(line.variance)"></button>
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums" :class="tone(line.variance)">
                                            <button type="button" x-on:click="open(line, 'variance')" class="underline decoration-dotted underline-offset-4" x-text="line.variance === 0 ? '—' : signedNaira(line.value)"></button>
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums" :class="tone(line.variance)">
                                            <button type="button" x-on:click="open(line, 'variance')" class="underline decoration-dotted underline-offset-4" x-text="line.variance === 0 ? '—' : signedNaira(line.value_cost)"></button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-gray-800 font-bold text-sm">
                                <tr>
                                    <td class="px-3 py-2 sticky left-0 bg-gray-50 dark:bg-gray-800">Totals <span class="font-normal text-xs text-gray-500" x-text="'(' + section().totals.variance_items + ' with variance)'"></span></td>
                                    <td colspan="5"></td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums" x-text="naira(section().totals.sales)"></td>
                                    <td colspan="3"></td>
                                    <td></td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums" :class="tone(section().totals.variance_value)" x-text="signedNaira(section().totals.variance_value)"></td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums" :class="tone(section().totals.variance_value_cost)" x-text="signedNaira(section().totals.variance_value_cost)"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </template>
            @endif

            {{-- Staff: sticky totals --}}
            <template x-if="! d.audit && section()">
                <div class="sticky bottom-0 z-10 -mx-1 px-4 py-3 rounded-t-xl bg-gray-900 text-white flex items-center justify-between gap-4 shadow-lg">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-400" x-text="tab === 'ingredient' ? 'Section' : 'Total sales'"></div>
                        <div class="font-mono tabular-nums font-bold" x-text="tab === 'ingredient' ? section().label : naira(section().totals.sales)"></div>
                    </div>
                    <div class="text-right">
                        <div class="text-[11px] uppercase tracking-wide text-gray-400">Total shortage</div>
                        <div class="font-mono tabular-nums font-bold" :class="section().totals.shortage_value > 0 ? 'text-red-400' : 'text-gray-300'"
                            x-text="section().totals.shortage_value > 0 ? '−' + naira(section().totals.shortage_value) : naira(0)"></div>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- ───────── Figure pop-up ───────── --}}
    <template x-if="modal">
        <div class="fixed inset-0 z-50 bg-black/50 flex items-end sm:items-center justify-center" x-on:click.self="close()" role="dialog" aria-modal="true">
            <div class="w-full sm:max-w-[640px] max-h-[85vh] flex flex-col rounded-t-2xl sm:rounded-2xl bg-white dark:bg-gray-900 shadow-xl">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="modal.line.name"></div>
                        <div class="font-bold text-gray-900 dark:text-white">
                            <span x-text="label(modal.figure)"></span>:
                            <span class="font-mono tabular-nums" :class="modal.figure === 'variance' ? tone(modal.line.variance) : ''"
                                x-text="['variance', 'unrecorded'].includes(modal.figure) ? signed(figureValue(modal.line, modal.figure)) : q(figureValue(modal.line, modal.figure))"></span>
                        </div>
                    </div>
                    <button type="button" x-on:click="close()" class="min-h-[44px] min-w-[44px] rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Close">✕</button>
                </div>

                <div class="overflow-y-auto px-4 py-3 text-sm">

                    {{-- Brought forward --}}
                    <template x-if="modal.figure === 'brought_forward'">
                        <div>
                            <template x-for="(m, i) in rows('brought_forward')" :key="i">
                                <div class="space-y-1">
                                    <div class="flex justify-between"><span class="text-gray-500">Counted</span><span class="font-mono tabular-nums" x-text="q(m.quantity)"></span></div>
                                    <div class="flex justify-between"><span class="text-gray-500">Counted by</span><span x-text="m.recorder || '—'"></span></div>
                                    <div class="flex justify-between"><span class="text-gray-500">Sealed by</span><span x-text="m.approver || '—'"></span></div>
                                    <div class="flex justify-between"><span class="text-gray-500">Sealed</span><span x-text="m.recorded_at || '—'"></span></div>
                                    <a x-show="d.previous" :href="d.previous?.url" class="inline-block mt-2 text-red-600 font-bold underline">Open previous count</a>
                                </div>
                            </template>
                            <p x-show="! rows('brought_forward').length" class="text-gray-500" x-text="modal.line.brought_forward_note || 'Nothing brought forward.'"></p>
                        </div>
                    </template>

                    {{-- Sold / used --}}
                    <template x-if="modal.figure === 'sold'">
                        <div>
                            <div x-show="modal.line.waiters.length" class="flex flex-wrap gap-2 mb-3">
                                <template x-for="w in modal.line.waiters" :key="w.name">
                                    <span class="px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-xs"><span class="font-bold" x-text="w.name"></span>: <span class="tabular-nums" x-text="q(w.quantity)"></span></span>
                                </template>
                            </div>
                            <template x-for="(m, i) in rows('sold')" :key="i">
                                <div class="py-2 border-b border-gray-100 dark:border-gray-800" :class="m.status === 'voided' ? 'opacity-60' : ''">
                                    <div class="flex justify-between gap-3">
                                        <div class="min-w-0" :class="m.status === 'voided' ? 'line-through' : ''">
                                            <span class="font-medium" x-text="m.waiter || m.label"></span>
                                            <span class="text-xs text-gray-500" x-show="m.order" x-text="' · ' + m.order"></span>
                                        </div>
                                        <div class="text-right font-mono tabular-nums" :class="m.status === 'voided' ? 'line-through' : ''">
                                            <span x-text="q(m.quantity)"></span><span class="text-xs text-gray-500" x-show="m.amount !== null" x-text="' · ' + naira(m.amount)"></span>
                                        </div>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        Placed <span x-text="m.placed_at || '—'"></span> · Ready <span x-text="m.ready_at || '—'"></span>
                                        <span x-show="m.slow" class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 font-bold" x-text="'Slow: ' + m.ready_minutes + ' min'"></span>
                                    </div>
                                    <div class="text-xs text-gray-500" x-show="m.dishes.length" x-text="m.dishes.join(', ')"></div>
                                    <div class="text-xs text-amber-700 dark:text-amber-300" x-show="m.comp" x-text="m.comp ? 'Comp: ' + q(m.comp.quantity) + ' (' + m.comp.reason + ')' + (m.comp.by ? ' by ' + m.comp.by : '') : ''"></div>
                                    <div class="text-xs text-gray-400" x-show="m.price_estimated">Price taken from the current product price (order line no longer exists).</div>
                                    <div class="text-xs text-red-600 dark:text-red-400 font-bold" x-show="m.status === 'voided'" x-text="'Voided' + (m.voided_by ? ' by ' + m.voided_by : '') + (m.voided_at ? ' · ' + m.voided_at : '') + ' — not counted'"></div>
                                </div>
                            </template>
                            <p x-show="! rows('sold').length" class="text-gray-500" x-text="tab === 'ingredient' ? 'Nothing used this shift' : 'No sales this shift'"></p>
                        </div>
                    </template>

                    {{-- Every other list figure --}}
                    <template x-if="['transferred', 'returns', 'other_in', 'damages', 'other_out', 'unrecorded'].includes(modal.figure)">
                        <div>
                            <p x-show="modal.figure === 'unrecorded'" class="text-xs text-gray-500 mb-2">Live stock differed from what the recorded movements add up to. This is the gap, plus any stock change recorded without saying whether it added or removed stock.</p>
                            <template x-for="(m, i) in rows(modal.figure)" :key="i">
                                <div class="py-2 border-b border-gray-100 dark:border-gray-800" :class="m.status !== 'active' ? 'opacity-60' : ''">
                                    <div class="flex justify-between gap-3">
                                        <div class="font-medium" x-text="m.label + (m.transfer ? ' · ' + m.transfer : '') + (m.order ? ' · ' + m.order : '')"></div>
                                        <div class="font-mono tabular-nums" x-text="modal.figure === 'unrecorded' && m.status === 'active' ? signed(m.quantity) : q(m.quantity)"></div>
                                    </div>
                                    <div class="text-xs text-gray-500" x-show="modal.figure === 'transferred'">
                                        Sent by <span x-text="m.sender || '—'"></span> · <span x-text="m.sent_at || '—'"></span><br>
                                        Received by <span x-text="m.receiver || '—'"></span> · <span x-text="m.received_at || '—'"></span>
                                    </div>
                                    <div class="text-xs text-gray-500" x-show="modal.figure === 'returns'">
                                        Returned by <span x-text="m.waiter || '—'"></span> · confirmed by <span x-text="m.recorder || '—'"></span> · <span x-text="m.recorded_at || '—'"></span>
                                    </div>
                                    <div class="text-xs text-gray-500" x-show="['damages', 'other_in', 'other_out'].includes(modal.figure)">
                                        Recorded by <span x-text="m.recorder || '—'"></span> · <span x-text="m.recorded_at || '—'"></span>
                                        <span x-show="m.approver"> · approved by <span x-text="m.approver"></span><span x-show="m.received_at" x-text="' · ' + m.received_at"></span></span>
                                    </div>
                                    <div class="text-xs text-gray-500" x-show="modal.figure === 'unrecorded' && m.recorded_at" x-text="(m.recorder || '—') + ' · ' + m.recorded_at"></div>
                                    <div class="text-xs text-gray-600 dark:text-gray-300 italic" x-show="m.reason" x-text="m.reason"></div>
                                    <div class="text-xs text-gray-400" x-show="m.status === 'info'">Shown for context — not part of the total.</div>
                                </div>
                            </template>
                            <p x-show="! rows(modal.figure).length" class="text-gray-500"
                                x-text="({ transferred: 'No transfers this shift', returns: 'No returns this shift', damages: 'No damages recorded', other_in: 'No other stock in', other_out: 'No other stock out', unrecorded: 'Nothing unrecorded' })[modal.figure]"></p>
                        </div>
                    </template>

                    {{-- Counted --}}
                    <template x-if="modal.figure === 'counted'">
                        <div class="space-y-1">
                            <div class="flex justify-between"><span class="text-gray-500">Counted by</span><span x-text="d.counted_by || '—'"></span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Submitted</span><span x-text="d.counted_at || '—'"></span></div>
                            <template x-for="s in d.signers" :key="s.label">
                                <div class="flex justify-between"><span class="text-gray-500" x-text="s.label + ' PIN'"></span><span x-text="s.name"></span></div>
                            </template>
                            <div class="flex justify-between"><span class="text-gray-500">Sealed</span><span x-text="d.sealed_at || '—'"></span></div>
                            <div class="text-xs text-gray-400" x-show="modal.line.pack && pack(modal.line, modal.line.counted)" x-text="'≈ ' + pack(modal.line, modal.line.counted)"></div>
                        </div>
                    </template>

                    {{-- Expected --}}
                    <template x-if="modal.figure === 'expected'">
                        <div>
                            <p class="font-mono tabular-nums text-gray-900 dark:text-white leading-relaxed" x-text="working(modal.line)"></p>
                            <p class="text-xs text-gray-500 mt-2">Tap any figure on the item to see the records behind it.</p>
                        </div>
                    </template>

                    {{-- Variance --}}
                    <template x-if="modal.figure === 'variance'">
                        <div class="space-y-3">
                            <p class="font-mono tabular-nums" :class="tone(modal.line.variance)" x-text="varianceWorking(modal.line)"></p>
                            <div x-show="modal.line.ruling" class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                                <div class="font-bold" x-text="modal.line.ruling?.label"></div>
                                <div class="text-xs text-gray-500" x-show="modal.line.ruling?.by" x-text="'By ' + modal.line.ruling?.by + (modal.line.ruling?.at ? ' · ' + modal.line.ruling.at : '')"></div>
                                <div class="text-xs italic text-gray-600 dark:text-gray-300" x-show="modal.line.ruling?.note" x-text="modal.line.ruling?.note"></div>
                                <div class="text-xs mt-1 text-red-700 dark:text-red-300 font-bold" x-show="modal.line.ruling?.debt" x-text="modal.line.ruling?.debt ? 'Debt booked to ' + (modal.line.ruling.debt.name || '—') + ': ' + naira(modal.line.ruling.debt.amount) : ''"></div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Explanations</div>
                                <template x-for="(n, i) in modal.line.notes" :key="i">
                                    <div class="py-2 border-b border-gray-100 dark:border-gray-800">
                                        <div class="whitespace-pre-line" x-text="n.body"></div>
                                        <div class="text-xs text-gray-500" x-text="n.author + ' · ' + n.at"></div>
                                    </div>
                                </template>
                                <p x-show="! modal.line.notes.length" class="text-gray-500 text-xs">No explanations added.</p>
                                <button type="button" x-show="modal.line.can_note && ! d.audit" x-on:click="startNote(modal.line)"
                                    class="mt-2 min-h-[44px] px-4 rounded-lg border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-bold">Add explanation</button>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Total row: always the figure tapped --}}
                <div x-show="! ['expected', 'counted', 'variance'].includes(modal.figure)"
                    class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex justify-between font-bold">
                    <span>Total</span>
                    <span class="font-mono tabular-nums">
                        <span x-text="modal.figure === 'unrecorded' ? signed(modal.line.unrecorded) : q(figureValue(modal.line, modal.figure))"></span>
                        <span class="text-xs text-gray-500 font-normal" x-show="modal.figure === 'sold' && modal.line.sales_amount !== null" x-text="' · ' + naira(modal.line.sales_amount)"></span>
                    </span>
                </div>
            </div>
        </div>
    </template>

    {{-- ───────── Explanation input ───────── --}}
    <template x-if="noteFor">
        <div class="fixed inset-0 z-[60] bg-black/50 flex items-end sm:items-center justify-center" x-on:click.self="noteFor = null">
            <div class="w-full sm:max-w-[640px] rounded-t-2xl sm:rounded-2xl bg-white dark:bg-gray-900 p-4 shadow-xl">
                <div class="text-xs text-gray-500" x-text="noteFor.name"></div>
                <div class="font-bold mb-2 text-gray-900 dark:text-white">Explain this variance (<span :class="tone(noteFor.variance)" x-text="signed(noteFor.variance)"></span>)</div>
                <textarea x-model="noteText" rows="4" maxlength="1000" x-init="$nextTick(() => $el.focus())"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm" placeholder="What happened?"></textarea>
                <p class="text-xs text-gray-500 mt-1">Saved with your name and the time. It can't be edited afterwards — add another note to correct it.</p>
                <div class="flex justify-end gap-2 mt-3">
                    <button type="button" x-on:click="noteFor = null" class="min-h-[44px] px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-sm">Cancel</button>
                    <button type="button" x-on:click="saveNote()" :disabled="saving || ! noteText.trim()" class="min-h-[44px] px-4 rounded-lg bg-red-600 text-white text-sm font-bold disabled:opacity-50" x-text="saving ? 'Saving…' : 'Save explanation'"></button>
                </div>
            </div>
        </div>
    </template>
</div>
