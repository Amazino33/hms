<x-filament-panels::page>
    {{-- 2-up at phone width (was 3 — cramped enough at 360px to threaten the
         at-a-glance color-coded read this board exists for), same tile
         format just bigger; unchanged from md up. --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3">
        @foreach($tiles as $tile)
            @php
                $room = $tile['room'];
                $booking = $tile['booking'];
                $occupancy = $tile['occupancy'];

                $styles = [
                    'vacant' => 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300',
                    'arriving_today' => 'bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700 text-blue-800 dark:text-blue-300',
                    'occupied' => 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-300 dark:border-indigo-700 text-indigo-800 dark:text-indigo-300',
                    'due_out_today' => 'bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-300',
                    'maintenance' => 'bg-gray-100 dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-500',
                ];
                $labels = [
                    'vacant' => $room->housekeeping === 'dirty' ? 'Vacant · Dirty' : 'Vacant',
                    'arriving_today' => 'Arriving Today',
                    'occupied' => 'Occupied',
                    'due_out_today' => 'Due Out Today',
                    'maintenance' => 'Maintenance',
                ];
                $href = $occupancy === 'maintenance'
                    ? null
                    : ($booking ? '/admin/folio?booking=' . $booking->id : '/admin/reservations-timeline');
            @endphp

            @if($href)
                <a href="{{ $href }}" class="block min-h-[72px] rounded-lg border-2 p-3 text-center hover:opacity-80 touch-manipulation flex flex-col items-center justify-center {{ $styles[$occupancy] }}">
                    <div class="text-xl font-bold">{{ $room->number }}</div>
                    <div class="text-xs font-semibold mt-1">{{ $labels[$occupancy] }}</div>
                    @if($booking)
                        <div class="text-xs truncate mt-1 w-full">{{ $booking->guest->name }}</div>
                    @endif
                </a>
            @else
                <div class="min-h-[72px] rounded-lg border-2 p-3 text-center flex flex-col items-center justify-center {{ $styles[$occupancy] }}">
                    <div class="text-xl font-bold">{{ $room->number }}</div>
                    <div class="text-xs font-semibold mt-1">{{ $labels[$occupancy] }}</div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400 mt-6">
        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-emerald-400 inline-block"></span> Vacant</div>
        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-blue-400 inline-block"></span> Arriving Today</div>
        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-indigo-400 inline-block"></span> Occupied</div>
        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-amber-400 inline-block"></span> Due Out Today</div>
        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-gray-400 inline-block"></span> Maintenance</div>
    </div>
</x-filament-panels::page>
