<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-gradient-to-r from-amber-50 to-orange-50 dark:from-gray-800 dark:to-gray-900 rounded-lg p-6 border border-amber-200 dark:border-gray-700">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Handover Discrepancies</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Shortages flagged at handover seal. Recount to verify, debit the outgoing custodian, pend for
                investigation, or resolve without a debit — every action is logged.
            </p>
        </div>

        @php $pendingDamages = $this->pendingDamages(); @endphp
        @if($pendingDamages->isNotEmpty())
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-amber-300 dark:border-amber-700 overflow-hidden">
                <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800">
                    <h3 class="font-bold text-gray-900 dark:text-white">Pending Damage Reports — context before you rule</h3>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                        Resolve these first if a discrepancy below might actually be an honestly reported breakage,
                        not a shortfall to debit. The "Pending damage" column on each row shows what would remain
                        if approved — the discrepancy's own figures never change on their own.
                    </p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($pendingDamages as $damage)
                        <div class="p-4 flex items-center justify-between gap-3" wire:key="pending-damage-{{ $damage->id }}">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $damage->itemName() }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $damage->warehouse->name }} · {{ number_format((float) $damage->quantity, 2) }} · {{ $damage->reportedBy->name ?? 'Unknown' }} · {{ $damage->created_at->diffForHumans() }}
                                </p>
                                <p class="text-sm text-gray-700 dark:text-gray-300 mt-1">{{ $damage->note }}</p>
                            </div>
                            <div class="flex gap-2 shrink-0">
                                <button
                                    wire:click="approvePendingDamage({{ $damage->id }})"
                                    wire:confirm="Approve this damage? Stock is written off at cost immediately and this cannot be undone."
                                    class="px-3 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-bold">
                                    Approve
                                </button>
                                <button
                                    x-data
                                    x-on:click="const reason = prompt('Reason for rejecting?'); if (reason) { $wire.rejectPendingDamage({{ $damage->id }}, reason) }"
                                    class="px-3 py-2 rounded-lg bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs font-bold">
                                    Reject
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
