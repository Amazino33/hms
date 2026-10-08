<x-filament-panels::page>
    @php($selected = $this->selectedSession())

    @if($selected)
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('session', null)" class="min-h-[44px] px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-sm">← All counts</button>
            <a href="{{ route('count-breakdown.csv', $selected->id) }}" class="min-h-[44px] inline-flex items-center px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-sm">Download CSV</a>
        </div>
        @include('filament.components.count-breakdown', [
            'payload' => $this->payload(),
            'pdfUrl' => route('count-breakdown.pdf', $selected->id),
        ])
    @elseif($traceSection !== null)
        <div class="flex flex-wrap items-end gap-3 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <button type="button" wire:click="$set('traceSection', null)" class="min-h-[44px] px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-sm">← All counts</button>
            <label class="text-sm">
                <span class="block text-xs text-gray-500 mb-1">Section</span>
                <select wire:model.live="traceSection" class="min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm">
                    <option value="product">Products</option>
                    <option value="ingredient">Ingredients</option>
                </select>
            </label>
            <label class="text-sm flex-1 min-w-[200px]">
                <span class="block text-xs text-gray-500 mb-1">Item</span>
                <select wire:model.live="traceItem" class="w-full min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm">
                    <option value="">Pick an item…</option>
                    @foreach($this->traceOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            @if($traceItem)
                <a href="{{ route('count-item-trace.csv', ['section' => $traceSection, 'item' => $traceItem]) }}" class="min-h-[44px] inline-flex items-center px-4 rounded-lg bg-gray-900 dark:bg-gray-700 text-white text-sm font-bold">Download CSV</a>
            @endif
        </div>
        @if($traceItem)
            @include('filament.components.count-item-trace-table', [
                'rows' => $this->traceRows(),
                'countUrl' => fn ($id) => "/ceo/count-breakdowns?session={$id}",
            ])
        @endif
    @else
        <div class="flex flex-wrap items-end gap-3 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-sm">
            <label><span class="block text-xs text-gray-500 mb-1">From</span><input type="date" wire:model.live="from" class="min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm"></label>
            <label><span class="block text-xs text-gray-500 mb-1">Until</span><input type="date" wire:model.live="until" class="min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm"></label>
            <label><span class="block text-xs text-gray-500 mb-1">Staff</span>
                <select wire:model.live="staffId" class="min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm">
                    <option value="">Anyone</option>
                    @foreach($this->staffOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label><span class="block text-xs text-gray-500 mb-1">Section</span>
                <select wire:model.live="section" class="min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm">
                    <option value="">All</option><option value="product">Products</option><option value="ingredient">Ingredients</option>
                </select>
            </label>
            <label class="min-h-[44px] inline-flex items-center gap-2"><input type="checkbox" wire:model.live="hasVariance" class="rounded border-gray-300 text-red-600"> Has variance</label>
            <button type="button" wire:click="$set('traceSection', 'product')" class="ml-auto min-h-[44px] px-4 rounded-lg bg-gray-900 dark:bg-gray-700 text-white font-bold">Item trace</button>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm whitespace-nowrap">
                    <thead class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="text-left px-3 py-2">Sealed</th>
                            <th class="text-left px-3 py-2">Count</th>
                            <th class="text-left px-3 py-2">Outgoing → Incoming</th>
                            <th class="text-right px-3 py-2">Sales ₦</th>
                            <th class="text-right px-3 py-2">Variance ₦ (sell)</th>
                            <th class="text-right px-3 py-2">Variance ₦ (cost)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($this->sessions() as $s)
                            @php($v = (float) $s->breakdown_variance_value)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer" wire:click="$set('session', {{ $s->id }})">
                                <td class="px-3 py-2">{{ $s->reviewed_at?->venueTime()->format('M j, g:i A') }}</td>
                                <td class="px-3 py-2">{{ \App\Services\CountBreakdownViewService::TYPE_LABELS[$s->type] ?? $s->type }} · {{ $s->warehouse?->name }}</td>
                                <td class="px-3 py-2">{{ $s->outgoingUser?->name ?? '—' }} → {{ $s->incomingUser?->name ?? '—' }}</td>
                                @if($s->breakdown_exists)
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">₦{{ number_format((float) $s->breakdown_sales, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums {{ $v < 0 ? 'text-red-600' : ($v > 0 ? 'text-amber-600' : 'text-gray-400') }}">{{ $v < 0 ? '−' : ($v > 0 ? '+' : '') }}₦{{ number_format(abs($v), 2) }}</td>
                                    @php($c = (float) $s->breakdown_variance_cost)
                                    <td class="px-3 py-2 text-right font-mono tabular-nums {{ $c < 0 ? 'text-red-600' : ($c > 0 ? 'text-amber-600' : 'text-gray-400') }}">{{ $c < 0 ? '−' : ($c > 0 ? '+' : '') }}₦{{ number_format(abs($c), 2) }}</td>
                                @else
                                    <td colspan="3" class="px-3 py-2 text-right text-xs text-gray-400">Breakdown not recorded (before this update)</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No sealed counts match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
