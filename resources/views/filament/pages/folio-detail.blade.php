<x-filament-panels::page>
    @if($booking)
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <div>
                    <div class="font-bold text-lg text-gray-900 dark:text-white">Room {{ $booking->room->number }} — {{ $booking->guest->name }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $booking->check_in->format('M j, Y') }} &rarr; {{ $booking->check_out->format('M j, Y') }}
                        · Status: <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $booking->status)) }}</span>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Balance</div>
                    <div class="text-2xl font-bold {{ ($booking->folio?->balance() ?? 0) > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                        ₦{{ number_format($booking->folio?->balance() ?? 0, 2) }}
                    </div>

                    @if($booking->isReserved())
                        <button type="button" wire:click="checkIn" class="mt-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm">
                            Check In
                        </button>
                    @elseif($booking->isCheckedIn())
                        <button type="button" wire:click="checkOut"
                            wire:confirm="Check out this guest? The folio balance must be zero and this seals the folio permanently."
                            class="mt-2 px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold text-sm">
                            Check Out
                        </button>
                    @elseif($booking->isCheckedOut())
                        <a href="{{ route('folio.pdf', $booking->id) }}" target="_blank"
                            class="mt-2 inline-block px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold text-sm">
                            Print Receipt
                        </a>
                    @endif
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white mb-3">Ledger</h3>

                {{-- Mobile: one card per line, nothing dropped that the
                     desktop table shows — date/type/description/by all
                     still present, just stacked instead of columned. --}}
                <div class="md:hidden space-y-2">
                    @forelse($booking->folio?->lines ?? [] as $line)
                        <div class="rounded-lg border border-gray-100 dark:border-gray-700 p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ $line->description }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ ucfirst(str_replace('_', ' ', $line->type)) }} · {{ $line->created_at->format('M j, g:ia') }} · {{ $line->createdBy?->name ?? '—' }}
                                    </div>
                                    @if($line->reference)
                                        <div class="text-xs text-gray-400">{{ $line->reference }}</div>
                                    @endif
                                    @if($line->type === 'payment' && $line->payment_method === 'transfer')
                                        <div class="text-xs font-bold {{ $line->verified ? 'text-emerald-600' : 'text-amber-600' }}">
                                            {{ $line->verified ? 'Verified' : 'Pending verification' }}
                                        </div>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right font-bold {{ $line->amount >= 0 ? 'text-red-600' : 'text-emerald-600' }}">
                                    {{ $line->amount >= 0 ? '+' : '' }}{{ number_format($line->amount, 2) }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 py-3">No charges or payments yet.</p>
                    @endforelse
                </div>

                <div class="hidden md:block overflow-x-auto hms-table-scroll">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1 pr-4">Date</th>
                                <th class="py-1 pr-4">Type</th>
                                <th class="py-1 pr-4">Description</th>
                                <th class="py-1 pr-4">By</th>
                                <th class="py-1 pr-4 text-right">Amount</th>
                                <th class="py-1 pr-4">Verified</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($booking->folio?->lines ?? [] as $line)
                                <tr class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-1 pr-4 text-gray-500">{{ $line->created_at->format('M j, g:ia') }}</td>
                                    <td class="py-1 pr-4 text-gray-700 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $line->type)) }}</td>
                                    <td class="py-1 pr-4 text-gray-900 dark:text-white">
                                        {{ $line->description }}
                                        @if($line->reference)
                                            <div class="text-xs text-gray-400">{{ $line->reference }}</div>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-4 text-gray-500">{{ $line->createdBy?->name ?? '—' }}</td>
                                    <td class="py-1 pr-4 text-right font-bold {{ $line->amount >= 0 ? 'text-red-600' : 'text-emerald-600' }}">
                                        {{ $line->amount >= 0 ? '+' : '' }}{{ number_format($line->amount, 2) }}
                                    </td>
                                    <td class="py-1 pr-4">
                                        @if($line->type === 'payment' && $line->payment_method === 'transfer')
                                            @if($line->verified)
                                                <span class="text-emerald-600 font-bold">Verified</span>
                                            @else
                                                <span class="text-amber-600 font-bold">Pending</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-3 text-gray-400">No charges or payments yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($booking->isCheckedOut())
                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4 border border-gray-200 dark:border-gray-700 text-sm text-gray-500">
                    This folio is sealed — the guest checked out {{ $booking->checked_out_at->format('M j, Y g:ia') }}. No further charges or payments can be added.
                </div>
            @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3">
                    <h3 class="font-bold text-gray-900 dark:text-white">Add incidental charge</h3>

                    <div class="flex flex-wrap gap-2">
                        @foreach($this->priceListItems() as $item)
                            <button type="button" wire:click="applyPriceListItem({{ $item->id }})"
                                class="px-3 py-1 rounded-full text-xs font-bold border {{ $selectedPriceListItemId === $item->id ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300' }}">
                                {{ $item->name }} (₦{{ number_format($item->price, 0) }})
                            </button>
                        @endforeach
                    </div>

                    <input type="text" wire:model="incidentalDescription" placeholder="Description"
                        class="w-full px-4 py-3 min-h-[48px] border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                    <div x-data="{ incidentalAmount: @entangle('incidentalAmount') }">
                        <x-mobile.numeric-pad model="incidentalAmount" :currency="true" label="Amount" />
                    </div>

                    <button type="button" wire:click="addIncidental" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold touch-manipulation">
                        Add charge
                    </button>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3">
                    <h3 class="font-bold text-gray-900 dark:text-white">Record payment</h3>

                    <div x-data="{ paymentAmount: @entangle('paymentAmount') }">
                        <x-mobile.numeric-pad model="paymentAmount" :currency="true" label="Amount" />
                    </div>

                    <div x-data="{ paymentMethod: @entangle('paymentMethod') }">
                        <x-mobile.chip-select model="paymentMethod" :options="['cash' => 'Cash', 'pos_terminal' => 'POS Terminal', 'transfer' => 'Bank Transfer']" />
                    </div>

                    @if($paymentMethod === 'transfer')
                        <input type="text" wire:model="paymentReference" placeholder="Transfer reference / sender name"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                        <p class="text-xs text-amber-600">Transfer payments post as pending until a supervisor verifies the alert.</p>
                    @endif

                    <button type="button" wire:click="recordPayment" class="w-full px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold">
                        Record payment
                    </button>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3 md:col-span-2">
                    <h3 class="font-bold text-gray-900 dark:text-white">Apply discount</h3>
                    <p class="text-xs text-gray-500">Only reduces the room charge — never an incidental (room-order food/drinks etc.).</p>

                    <input type="text" wire:model="discountReason" placeholder="Reason (required)"
                        class="w-full px-4 py-3 min-h-[48px] border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                    <div x-data="{ discountAmount: @entangle('discountAmount') }">
                        <x-mobile.numeric-pad model="discountAmount" :currency="true" label="Amount" />
                    </div>

                    <button type="button" wire:click="applyDiscount" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold touch-manipulation">
                        Apply discount
                    </button>
                </div>
            </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
