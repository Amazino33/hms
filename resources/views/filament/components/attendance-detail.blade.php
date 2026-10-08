@php
    use App\Support\VenueTime;

    $tz = VenueTime::TIMEZONE;
    $keptIds = array_filter([$record->clock_in_log_id, $record->clock_out_log_id]);
    $activeFines = $record->fines->whereNull('voided_at');
    $voidedFines = $record->fines->whereNotNull('voided_at');
@endphp

<div class="space-y-6 text-sm">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="text-gray-500 dark:text-gray-400">Scheduled</div>
            <div class="font-medium text-gray-950 dark:text-white">
                {{ $record->scheduled_start_at->timezone($tz)->format('j M, H:i') }}
                →
                {{ $record->scheduled_end_at->timezone($tz)->format('j M, H:i') }}
            </div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Outcome</div>
            <div class="font-medium text-gray-950 dark:text-white">{{ $record->outcomeLabel() }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Counted window</div>
            <div class="text-gray-700 dark:text-gray-300">
                {{ $record->window_start_at->timezone($tz)->format('j M, H:i') }}
                →
                {{ $record->window_end_at->timezone($tz)->format('j M, H:i') }}
            </div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Punches</div>
            <div class="text-gray-700 dark:text-gray-300">
                {{ $record->raw_punch_count }} recorded, {{ $record->collapsed_punch_count }} counted
            </div>
        </div>
    </div>

    @if ($record->review_flags)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
            <div class="font-semibold text-amber-900 dark:text-amber-200">Needs a look</div>
            <ul class="mt-1 list-inside list-disc text-amber-800 dark:text-amber-300">
                @foreach ($record->review_flags as $flag)
                    <li>{{ str_replace('_', ' ', $flag) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div>
        <div class="font-semibold text-gray-950 dark:text-white">Punches in the window</div>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Everything the terminal recorded. Greyed rows were collapsed as duplicates —
            a second touch within the duplicate window counts as the same punch.
        </p>

        @if ($punches->isEmpty())
            <p class="mt-3 text-gray-500 dark:text-gray-400">No punches at all inside this shift's window.</p>
        @else
            <table class="mt-3 w-full">
                <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-1 pr-4 font-medium">Time</th>
                        <th class="py-1 pr-4 font-medium">Counted</th>
                        <th class="py-1 font-medium">Device state</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($punches as $punch)
                        @php $counted = in_array($punch->id, $keptIds, true); @endphp
                        <tr @class(['text-gray-400 dark:text-gray-500' => ! $counted])>
                            <td class="py-1.5 pr-4 whitespace-nowrap">
                                {{ $punch->punch_time->timezone($tz)->format('j M, H:i:s') }}
                            </td>
                            <td class="py-1.5 pr-4">
                                {{ $counted ? 'Yes' : 'Collapsed' }}
                            </td>
                            <td class="py-1.5 text-xs">
                                {{ $punch->punch_state ?? '—' }}
                                <span class="text-gray-400 dark:text-gray-500">(not used)</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div>
        <div class="font-semibold text-gray-950 dark:text-white">Charges</div>

        @if ($activeFines->isEmpty() && $voidedFines->isEmpty())
            <p class="mt-2 text-gray-500 dark:text-gray-400">None.</p>
        @else
            <ul class="mt-2 space-y-1">
                @foreach ($activeFines as $fine)
                    <li class="flex items-center justify-between gap-4">
                        <span class="text-gray-700 dark:text-gray-300">
                            {{ $fine->typeLabel() }}
                            @if ($fine->kind === 'pay_deduction')
                                <span class="text-xs text-gray-500 dark:text-gray-400">(pay withheld, not a fine)</span>
                            @endif
                        </span>
                        <span class="font-medium text-gray-950 dark:text-white">
                            ₦{{ number_format($fine->amount) }}
                            <span @class([
                                'ml-1 rounded px-1.5 py-0.5 text-xs',
                                'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400' => $fine->is_shadow,
                                'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' => ! $fine->is_shadow,
                            ])>{{ $fine->is_shadow ? 'shadow' : 'live' }}</span>
                        </span>
                    </li>
                @endforeach

                @foreach ($voidedFines as $fine)
                    <li class="flex items-center justify-between gap-4 text-gray-400 line-through dark:text-gray-500">
                        <span>{{ $fine->typeLabel() }}</span>
                        <span>₦{{ number_format($fine->amount) }}</span>
                    </li>
                @endforeach
            </ul>

            @if ($voidedFines->isNotEmpty())
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Struck-through charges were voided: {{ $voidedFines->first()->void_reason }}
                </p>
            @endif
        @endif
    </div>

    @if ($record->superseded_at || $record->supersede_reason)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="font-semibold text-gray-950 dark:text-white">Superseded</div>
            <p class="mt-1 text-gray-600 dark:text-gray-400">
                {{ $record->supersede_reason }}
                ({{ $record->superseded_at?->timezone($tz)->format('j M Y, H:i') }})
            </p>
        </div>
    @endif
</div>
