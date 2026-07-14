<x-filament-panels::page>
    <div class="space-y-4">
        <div class="overflow-x-auto hms-table-scroll bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="grid" style="grid-template-columns: 90px repeat({{ count($days) }}, minmax(64px, 1fr)); min-width: {{ 90 + count($days) * 64 }}px;">
                {{-- Header row --}}
                <div class="sticky left-0 bg-gray-50 dark:bg-gray-900 border-b border-r border-gray-200 dark:border-gray-700 px-2 py-2 text-xs font-bold text-gray-500 dark:text-gray-400 z-10">
                    Room
                </div>
                @foreach($days as $day)
                    <div class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-1 py-2 text-center {{ $day->isToday() ? 'bg-primary-50 dark:bg-primary-900/30' : '' }}">
                        <div class="text-xs font-bold text-gray-700 dark:text-gray-300">{{ $day->format('D') }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $day->format('M j') }}</div>
                    </div>
                @endforeach

                {{-- Room rows --}}
                @foreach($rooms as $room)
                    <div class="sticky left-0 bg-white dark:bg-gray-800 border-b border-r border-gray-200 dark:border-gray-700 px-2 py-3 text-sm font-semibold text-gray-900 dark:text-white z-10 flex items-center">
                        {{ $room->number }}
                    </div>

                    <div class="relative col-span-{{ count($days) }} border-b border-gray-200 dark:border-gray-700" style="grid-column: span {{ count($days) }}; display: grid; grid-template-columns: repeat({{ count($days) }}, minmax(64px, 1fr));">
                        @foreach($days as $i => $day)
                            <button
                                type="button"
                                wire:click="openForm({{ $room->id }}, '{{ $day->toDateString() }}')"
                                class="h-12 border-r border-gray-100 dark:border-gray-700 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors"
                                style="grid-column: {{ $i + 1 }};"
                                title="New reservation — Room {{ $room->number }}, {{ $day->format('M j') }}"
                            ></button>
                        @endforeach

                        @foreach($bars[$room->id] ?? [] as $bar)
                            <button
                                type="button"
                                wire:click="openDetails({{ $bar['booking_id'] }})"
                                class="absolute top-1 h-10 rounded px-2 flex items-center text-xs font-bold text-white truncate cursor-pointer hover:opacity-90
                                    {{ $bar['status'] === 'reserved' ? 'bg-amber-500' : ($bar['status'] === 'checked_in' ? 'bg-emerald-600' : 'bg-gray-500') }}"
                                style="grid-column: {{ $bar['start'] + 1 }} / span {{ $bar['span'] }}; left: calc({{ $bar['start'] }} * (100% / {{ count($days) }})); width: calc({{ $bar['span'] }} * (100% / {{ count($days) }}) - 4px);"
                                title="{{ $bar['guest_name'] }} ({{ ucfirst($bar['status']) }})"
                            >
                                {{ $bar['guest_name'] }}
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex gap-4 text-xs text-gray-500 dark:text-gray-400">
            <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-amber-500 inline-block"></span> Reserved</div>
            <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-emerald-600 inline-block"></span> Checked in</div>
            <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-gray-500 inline-block"></span> Other</div>
        </div>
    </div>

    @if($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" wire:click.self="closeForm">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md space-y-4">
                <h3 class="font-bold text-lg text-gray-900 dark:text-white">
                    New reservation — Room {{ $selectedRoomNumber }}
                </h3>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Guest name</label>
                    <input type="text" wire:model="guestName" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Phone (optional)</label>
                    <input type="text" wire:model="guestPhone" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Check-in</label>
                        <input type="date" wire:model="checkIn" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Check-out</label>
                        <input type="date" wire:model="checkOut" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Deposit (optional)</label>
                    <input type="number" step="0.01" wire:model="deposit" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold">
                        Cancel
                    </button>
                    <button type="button" wire:click="submit" class="px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
                        Create reservation
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if($showDetails)
        @php($booking = $this->selectedBooking())
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" wire:click.self="closeDetails">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md space-y-4">
                @if($booking)
                    <h3 class="font-bold text-lg text-gray-900 dark:text-white">
                        Room {{ $booking->room->number }} — {{ $booking->guest->name }}
                    </h3>

                    <div class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                        <div>{{ $booking->check_in->format('M j, Y') }} &rarr; {{ $booking->check_out->format('M j, Y') }}</div>
                        <div>Status: <span class="font-bold">{{ ucfirst(str_replace('_', ' ', $booking->status)) }}</span></div>
                        <div>Folio balance: <span class="font-bold">₦{{ number_format($booking->folio?->balance() ?? 0, 2) }}</span></div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="closeDetails" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold">
                            Close
                        </button>
                        <a href="/admin/folio?booking={{ $booking->id }}" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold">
                            View Folio
                        </a>
                        @if($booking->isReserved())
                            <button type="button" wire:click="checkInSelected" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold">
                                Check In
                            </button>
                        @endif
                    </div>
                @else
                    <p class="text-gray-500">Booking not found.</p>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
