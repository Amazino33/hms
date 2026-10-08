@php
    $flagged = $this->flaggedRecords();
@endphp

<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
        <p class="text-gray-700 dark:text-gray-300">
            Nothing on this page carries a charge. These are the cases where fining somebody
            would be punishing them for a problem that is not theirs — a punch with no shift
            behind it, a day the terminal was probably offline, or a person nobody has linked
            to a badge yet.
        </p>
    </div>

    {{ $this->table }}

    @if ($flagged->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Flagged shifts</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Judged shifts carrying something worth a second look. These keep whatever
                charge they earned — the flag is a note, not a dispute.
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Date</th>
                            <th class="py-2 pr-4 font-medium">Staff</th>
                            <th class="py-2 pr-4 font-medium">Shift</th>
                            <th class="py-2 pr-4 font-medium">Outcome</th>
                            <th class="py-2 font-medium">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($flagged as $record)
                            <tr>
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    {{ $record->shift_date->format('j M Y') }}
                                </td>
                                <td class="py-2 pr-4">{{ $record->user?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $record->template?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $record->outcomeLabel() }}</td>
                                <td class="py-2">
                                    @foreach (array_intersect($record->review_flags ?? [], \App\Filament\Pages\AttendanceReviewQueue::FLAGGED) as $flag)
                                        <span class="mr-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">
                                            {{ str_replace('_', ' ', $flag) }}
                                        </span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
