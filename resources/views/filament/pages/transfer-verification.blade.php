<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto hms-table-scroll">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-4">Room</th>
                        <th class="py-1 pr-4">Guest</th>
                        <th class="py-1 pr-4">Reference</th>
                        <th class="py-1 pr-4">Recorded by</th>
                        <th class="py-1 pr-4 text-right">Amount</th>
                        <th class="py-1 pr-4"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lines as $line)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-2 pr-4 font-bold text-gray-900 dark:text-white">{{ $line->folio->booking->room->number }}</td>
                            <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ $line->folio->booking->guest->name }}</td>
                            <td class="py-2 pr-4 text-gray-500">{{ $line->reference ?? '—' }}</td>
                            <td class="py-2 pr-4 text-gray-500">{{ $line->createdBy?->name ?? '—' }}</td>
                            <td class="py-2 pr-4 text-right font-bold text-emerald-600">₦{{ number_format(abs($line->amount), 2) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">
                                <button type="button" wire:click="verify({{ $line->id }})" class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs">
                                    Verify
                                </button>
                                <button type="button" wire:click="openReject({{ $line->id }})" class="px-3 py-1 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold text-xs">
                                    Reject
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-gray-400">No transfer payments awaiting verification.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($rejectingLineId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" wire:click.self="closeReject">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md space-y-4">
                <h3 class="font-bold text-lg text-gray-900 dark:text-white">Reject transfer payment</h3>
                <textarea wire:model="rejectReason" rows="3" placeholder="Reason (e.g. no matching alert received)"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeReject" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold">
                        Cancel
                    </button>
                    <button type="button" wire:click="reject" class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold">
                        Reject payment
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
