{{--
    One item's breakdown line across every recorded count, oldest first.
    Expects: $rows (from CountBreakdownViewService::trace()), $countUrl
    (callable: session id => url).
--}}
@php
    $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $signed = fn ($n) => (float) $n < 0 ? '−'.$q(abs($n)) : ((float) $n > 0 ? '+'.$q($n) : '0');
    $tone = fn ($n) => (float) $n < 0 ? 'text-red-600 dark:text-red-400' : ((float) $n > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400');
@endphp
@if($rows->isEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-sm text-gray-500">
        No recorded counts for this item yet.
    </div>
@else
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm whitespace-nowrap">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="text-left px-3 py-2">Sealed</th>
                        <th class="text-left px-3 py-2">Staff</th>
                        <th class="text-right px-3 py-2">B/F</th>
                        <th class="text-right px-3 py-2">Transferred</th>
                        <th class="text-right px-3 py-2">Returns</th>
                        <th class="text-right px-3 py-2">Sold</th>
                        <th class="text-right px-3 py-2">Damages</th>
                        <th class="text-right px-3 py-2">Expected</th>
                        <th class="text-right px-3 py-2">Counted</th>
                        <th class="text-right px-3 py-2">Variance</th>
                        <th class="text-right px-3 py-2">Variance ₦</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($rows as $row)
                        @php($l = $row['line'])
                        <tr>
                            <td class="px-3 py-2">
                                <a href="{{ $countUrl($l->count_session_id) }}" class="text-red-600 dark:text-red-400 font-medium underline">{{ $row['sealed_at'] }}</a>
                                <div class="text-xs text-gray-500">{{ $row['session']?->warehouse?->name }} · #{{ $l->count_session_id }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">
                                {{ $row['session']?->outgoingUser?->name ?? '—' }} → {{ $row['session']?->incomingUser?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $q($l->brought_forward) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $q($l->transferred_in) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $q($l->returns_in) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $q($l->sold_qty) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $q($l->damages_writeoffs) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $q($l->expected_remaining) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $q($l->counted) }}</td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums font-bold {{ $tone($l->variance_qty) }}">
                                {{ $signed($l->variance_qty) }}
                                @if($row['repeat'])
                                    <span class="ml-1 px-1.5 py-0.5 rounded bg-red-600 text-white text-[10px] font-bold">Short {{ $row['repeat']['short'] }} of last {{ $row['repeat']['of'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums {{ $tone($l->variance_qty) }}">
                                {{ abs((float) $l->variance_qty) > 0 ? ((float) $l->variance_value_selling < 0 ? '−' : '+').'₦'.number_format(abs((float) $l->variance_value_selling), 2) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
