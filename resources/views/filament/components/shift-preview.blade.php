@php
    /** @var \Illuminate\Support\Collection $shifts */
@endphp

<div class="space-y-3">
    @if ($shifts->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            No shifts in the next 90 days. Either there is no schedule, the assignment has
            ended, or this person is exempt from attendance.
        </p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Times are venue time ({{ $timezone }}). A rotation anchored one day out will be
            obvious here before anybody is fined for it.
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Date</th>
                        <th class="py-2 pr-4 font-medium">Starts</th>
                        <th class="py-2 pr-4 font-medium">Ends</th>
                        <th class="py-2 font-medium">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($shifts as $shift)
                        <tr>
                            <td class="py-2 pr-4 whitespace-nowrap">
                                {{ $shift->startsAt->format('D j M Y') }}
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap">
                                {{ $shift->startsAt->format('H:i') }}
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap">
                                {{ $shift->endsAt->format('H:i') }}
                                @if ($shift->crossesMidnight())
                                    <span class="text-gray-500 dark:text-gray-400">
                                        (+{{ $shift->startsAt->startOfDay()->diffInDays($shift->endsAt->startOfDay()) }} day)
                                    </span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if ($shift->isHandover)
                                    <span class="text-amber-600 dark:text-amber-400">Handover</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
