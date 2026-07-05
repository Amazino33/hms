<x-filament-panels::page>
    @php($session = $this->session)

    @if($session)
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Warehouse</div>
                    <div class="font-semibold text-gray-900 dark:text-white">{{ $session->warehouse->name }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Status</div>
                    <div class="font-semibold text-gray-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $session->status)) }}</div>
                </div>
                @if($session->outgoingUser)
                    <div>
                        <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Outgoing</div>
                        <div class="font-semibold text-gray-900 dark:text-white">
                            {{ $session->outgoingUser->name }}
                            @if($session->confirmed_by_outgoing_at) <span class="text-green-600">✓ confirmed</span> @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400 uppercase text-xs font-bold">Incoming</div>
                        <div class="font-semibold text-gray-900 dark:text-white">
                            {{ $session->incomingUser->name }}
                            @if($session->confirmed_by_incoming_at) <span class="text-green-600">✓ confirmed</span> @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if($session->status === 'counting')
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left px-4 py-2">Item</th>
                            <th class="text-left px-4 py-2">Sub-location entries</th>
                            <th class="text-left px-4 py-2">Add count</th>
                            <th class="text-left px-4 py-2">Total counted so far</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($session->items as $item)
                            <tr wire:key="counting-row-{{ $item->id }}">
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $item->itemName() }}</td>
                                <td class="px-4 py-2">
                                    @foreach($item->subCounts as $sub)
                                        <div class="text-xs text-gray-500">{{ $sub->sub_location }}: {{ $sub->quantity }}</div>
                                    @endforeach
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex gap-2">
                                        <input type="text" wire:model="subLocationInputs.{{ $item->id }}.location" placeholder="Sub-location" class="border rounded px-2 py-1 w-28 text-xs dark:bg-gray-800 dark:border-gray-600">
                                        <input type="number" step="0.01" wire:model="subLocationInputs.{{ $item->id }}.qty" placeholder="Qty" class="border rounded px-2 py-1 w-20 text-xs dark:bg-gray-800 dark:border-gray-600">
                                        <button wire:click="recordCount({{ $item->id }})" class="px-2 py-1 bg-primary-500 text-white rounded text-xs font-bold">Save</button>
                                    </div>
                                </td>
                                <td class="px-4 py-2 font-mono font-bold">{{ $item->counted_quantity ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap gap-2 mt-4">
                @if($session->outgoing_user_id && !$session->confirmed_by_outgoing_at)
                    <button wire:click="confirmOutgoing" class="px-4 py-2 rounded-lg bg-amber-500 text-white font-bold text-sm">Confirm as Outgoing Custodian</button>
                @endif
                @if($session->incoming_user_id && !$session->confirmed_by_incoming_at)
                    <button wire:click="confirmIncoming" class="px-4 py-2 rounded-lg bg-amber-500 text-white font-bold text-sm">Confirm as Incoming Custodian</button>
                @endif
                <button wire:click="submitForReview" wire:confirm="Submit this count for manager review? You cannot add more counts afterwards."
                    class="px-4 py-2 rounded-lg bg-primary-600 text-white font-bold text-sm">Submit for Review</button>
            </div>
        @endif

        @if($session->status === 'pending_review')
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left px-4 py-2">Item</th>
                            <th class="text-left px-4 py-2">Expected</th>
                            <th class="text-left px-4 py-2">Counted</th>
                            <th class="text-left px-4 py-2">Variance</th>
                            <th class="text-left px-4 py-2">Decision</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($session->items as $item)
                            <tr wire:key="review-row-{{ $item->id }}">
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $item->itemName() }}</td>
                                <td class="px-4 py-2 font-mono">{{ $item->adjusted_expected_quantity }}</td>
                                <td class="px-4 py-2 font-mono">{{ $item->counted_quantity ?? 0 }}</td>
                                <td class="px-4 py-2 font-mono font-bold {{ $item->variance < 0 ? 'text-red-600' : ($item->variance > 0 ? 'text-green-600' : '') }}">
                                    {{ $item->variance }}
                                </td>
                                <td class="px-4 py-2">
                                    @if($item->decision)
                                        <span class="font-bold">{{ ucwords(str_replace('_', ' ', $item->decision)) }}</span>
                                    @elseif(abs($item->variance) < 0.0001)
                                        <span class="text-gray-400 italic">No variance</span>
                                    @else
                                        <div class="flex gap-2">
                                            <select wire:model="reviewDecisions.{{ $item->id }}" class="border rounded px-2 py-1 text-xs dark:bg-gray-800 dark:border-gray-600">
                                                <option value="">Choose…</option>
                                                <option value="true_up">True-up only</option>
                                                <option value="accountability">True-up + Accountability</option>
                                                <option value="ignored">Ignore</option>
                                            </select>
                                            <input type="text" wire:model="reviewNotes.{{ $item->id }}" placeholder="Notes" class="border rounded px-2 py-1 text-xs w-32 dark:bg-gray-800 dark:border-gray-600">
                                            <button wire:click="decideItem({{ $item->id }})" class="px-2 py-1 bg-primary-500 text-white rounded text-xs font-bold">Save</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <button wire:click="finalizeReview" wire:confirm="Finalize this session? This cannot be undone."
                    class="px-4 py-2 rounded-lg bg-success-600 text-white font-bold text-sm">Finalize Session</button>
            </div>
        @endif

        @if($session->status === 'reviewed')
            @if($this->canStartMyShift())
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                        This was your opening count — no one to hand over from. Start your shift now to begin selling against this stock.
                    </p>
                    <button wire:click="startMyShift" wire:confirm="Start your shift from this count?"
                        class="px-4 py-2 rounded-lg bg-success-600 text-white font-bold text-sm">Start My Shift</button>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left px-4 py-2">Item</th>
                            <th class="text-left px-4 py-2">Variance</th>
                            <th class="text-left px-4 py-2">Decision</th>
                            <th class="text-left px-4 py-2">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($session->items as $item)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $item->itemName() }}</td>
                                <td class="px-4 py-2 font-mono">{{ $item->variance }}</td>
                                <td class="px-4 py-2">{{ $item->decision ? ucwords(str_replace('_', ' ', $item->decision)) : '—' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $item->decision_notes }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
