<x-filament-panels::page>
    @php
        $window = $this->window();
        $totals = $this->totals();
    @endphp

    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From</label>
                <input type="date" wire:model.live="dateFrom" @disabled($countSessionId)
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm disabled:opacity-50">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To</label>
                <input type="date" wire:model.live="dateTo" @disabled($countSessionId)
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm disabled:opacity-50">
            </div>
            <div class="lg:col-span-2">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Or trace a count session</label>
                <select wire:model.live="countSessionId"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    <option value="">— use the date range above —</option>
                    @foreach ($this->countSessions() as $session)
                        <option value="{{ $session->id }}">
                            #{{ $session->id }} · {{ $session->warehouse?->name ?? 'Unknown' }} ·
                            {{ $session->opened_at?->format('M j, Y g:i A') }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Waiter</label>
                <select wire:model.live="waiterId"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    <option value="">All waiters</option>
                    @foreach ($this->waiters() as $waiter)
                        <option value="{{ $waiter->id }}">{{ $waiter->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Category</label>
                <select wire:model.live="categoryId"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    <option value="">All categories</option>
                    @foreach ($this->categories() as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="itemType"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                <option value="">Products and menu items</option>
                <option value="product">Products only</option>
                <option value="menu_item">Menu items only</option>
            </select>

            <div class="flex rounded-lg overflow-hidden border border-gray-300 dark:border-gray-600">
                <button type="button" wire:click="setViewMode('long')"
                    class="px-3 py-1.5 text-sm {{ $viewMode === 'long' ? 'bg-primary-600 text-white' : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300' }}">
                    Detailed rows
                </button>
                <button type="button" wire:click="setViewMode('tally')"
                    class="px-3 py-1.5 text-sm {{ $viewMode === 'tally' ? 'bg-primary-600 text-white' : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300' }}">
                    Waiter tally
                </button>
            </div>

            @if ($countSessionId)
                <button type="button" wire:click="clearCountSession"
                    class="px-3 py-1.5 text-sm rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                    Clear count session
                </button>
            @endif

            <button type="button" wire:click="exportCsv"
                class="px-3 py-1.5 text-sm rounded-lg bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 ml-auto">
                Export CSV
            </button>
        </div>

        {{-- Which window produced these numbers is never left to guesswork:
             a hand-typed range and a real count window rarely match. --}}
        <div class="text-xs rounded-lg px-3 py-2 {{ $window['exact'] ? 'bg-success-50 text-success-800 dark:bg-success-900/30 dark:text-success-200' : 'bg-warning-50 text-warning-800 dark:bg-warning-900/30 dark:text-warning-200' }}">
            {{ $window['label'] }}
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach ([
            ['Units billed', number_format($totals['billed'], 2), 'text-gray-900 dark:text-white'],
            ['Revenue', '₦' . number_format($totals['revenue'], 2), 'text-gray-900 dark:text-white'],
            ['Stock mismatches', $totals['mismatches'], $totals['mismatches'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-900 dark:text-white'],
            ['Recipe not deducted', $totals['recipe_problems'], $totals['recipe_problems'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-900 dark:text-white'],
        ] as [$label, $value, $tone])
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="text-xl font-bold {{ $tone }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    @if ($viewMode === 'long')
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2">Item</th>
                            <th class="px-4 py-2">Waiter</th>
                            <th class="px-4 py-2 text-right">Billed</th>
                            <th class="px-4 py-2 text-right">Left stock</th>
                            <th class="px-4 py-2 text-right">Gap</th>
                            <th class="px-4 py-2 text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($this->rows() as $row)
                            @php $hasGap = $row['mismatch'] !== null && abs($row['mismatch']) > 0.0001; @endphp
                            <tr class="{{ $hasGap ? 'bg-danger-50 dark:bg-danger-900/20' : '' }}">
                                <td class="px-4 py-2 whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="px-4 py-2">
                                    {{ $row['item_name'] }}
                                    <span class="text-xs text-gray-400">· {{ $row['category_name'] }}</span>
                                </td>
                                <td class="px-4 py-2">{{ $row['waiter_name'] }}</td>
                                <td class="px-4 py-2 text-right font-medium">{{ rtrim(rtrim(number_format($row['billed_quantity'], 2), '0'), '.') }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($row['item_type'] === 'product')
                                        {{ rtrim(rtrim(number_format($row['deducted_quantity'], 2), '0'), '.') }}
                                    @else
                                        {{-- A dish moves ingredients, not products: a zero here would
                                             read as a discrepancy that does not exist. --}}
                                        @php $status = $row['recipe_status']; @endphp
                                        <span @class([
                                            'text-xs px-2 py-0.5 rounded',
                                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $status === 'no_recipe',
                                            'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300' => $status === 'recorded',
                                            'bg-warning-100 text-warning-800 dark:bg-warning-900/40 dark:text-warning-300' => $status === 'partial',
                                            'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300' => $status === 'missing',
                                        ])>
                                            {{ match ($status) {
                                                'no_recipe' => 'no recipe',
                                                'recorded' => 'ingredients deducted',
                                                'partial' => 'partly deducted',
                                                'missing' => 'not deducted',
                                                default => '—',
                                            } }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right {{ $hasGap ? 'font-bold text-danger-600 dark:text-danger-400' : 'text-gray-400' }}">
                                    {{ $row['mismatch'] === null ? '—' : ($row['mismatch'] > 0 ? '+' : '') . rtrim(rtrim(number_format($row['mismatch'], 2), '0'), '.') }}
                                </td>
                                <td class="px-4 py-2 text-right">₦{{ number_format($row['revenue'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No sales in this window.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        @php $tally = $this->tally(); @endphp
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3">
                <span class="text-sm text-gray-500 dark:text-gray-400">Day</span>
                <select wire:model.live="tallyDate"
                    class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    @forelse ($this->availableDates() as $date)
                        <option value="{{ $date }}">{{ $date }}</option>
                    @empty
                        <option value="">No days with sales</option>
                    @endforelse
                </select>
                <span class="text-xs text-gray-400">Quantities are units billed.</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2">Item</th>
                            @foreach ($tally['waiters'] as $waiter)
                                <th class="px-4 py-2 text-right whitespace-nowrap">{{ $waiter['name'] }}</th>
                            @endforeach
                            <th class="px-4 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($tally['items'] as $item)
                            <tr>
                                <td class="px-4 py-2">{{ $item['name'] }}</td>
                                @foreach ($tally['waiters'] as $waiter)
                                    <td class="px-4 py-2 text-right">
                                        {{ isset($item['by_waiter'][$waiter['id']]) ? rtrim(rtrim(number_format($item['by_waiter'][$waiter['id']], 2), '0'), '.') : '—' }}
                                    </td>
                                @endforeach
                                <td class="px-4 py-2 text-right font-bold">{{ rtrim(rtrim(number_format($item['total'], 2), '0'), '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="99" class="px-4 py-6 text-center text-gray-400">No sales on this day.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- The movement ladder: sales alone can never close a variance, since
         a transfer, a damage write-off or an adjustment moves stock without
         any sale existing. Only shown for items that actually landed off. --}}
    @if ($countSessionId && $this->ladders()->isNotEmpty())
        <div class="space-y-4">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Why the count came up short</h3>

            @foreach ($this->ladders() as $ladder)
                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $ladder['item_name'] }}</span>
                        <span class="text-xs text-gray-400">· {{ $ladder['item_type'] === 'product' ? 'Product' : 'Ingredient' }}</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $ladder['opening_source'] }}</p>
                    </div>

                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr>
                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">Opening (last count)</td>
                                <td class="px-4 py-2 text-right">{{ $ladder['opening'] === null ? 'unknown' : rtrim(rtrim(number_format($ladder['opening'], 2), '0'), '.') }}</td>
                            </tr>
                            @foreach ($ladder['movements'] as $movement)
                                <tr>
                                    <td class="px-4 py-2 pl-8 text-gray-600 dark:text-gray-300">
                                        {{ $movement['label'] }}
                                        <span class="text-xs text-gray-400">
                                            · {{ $movement['at']?->format('M j, g:i A') }}{{ $movement['user_name'] ? ' · ' . $movement['user_name'] : '' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right {{ $movement['direction_known'] ? '' : 'text-warning-600 dark:text-warning-400' }}">
                                        @if ($movement['direction_known'])
                                            {{ $movement['signed_quantity'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($movement['signed_quantity'], 2), '0'), '.') }}
                                        @else
                                            ±{{ rtrim(rtrim(number_format($movement['quantity'], 2), '0'), '.') }}
                                            <span class="text-xs">(direction not recorded)</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="bg-gray-50 dark:bg-gray-900/50 font-semibold">
                                <td class="px-4 py-2">Expected</td>
                                <td class="px-4 py-2 text-right">{{ $ladder['expected'] === null ? 'unknown' : rtrim(rtrim(number_format($ladder['expected'], 2), '0'), '.') }}</td>
                            </tr>
                            <tr class="font-semibold">
                                <td class="px-4 py-2">Counted</td>
                                <td class="px-4 py-2 text-right">{{ $ladder['counted'] === null ? '—' : rtrim(rtrim(number_format($ladder['counted'], 2), '0'), '.') }}</td>
                            </tr>
                            <tr class="bg-danger-50 dark:bg-danger-900/20 font-bold">
                                <td class="px-4 py-2 text-danger-700 dark:text-danger-300">Unexplained</td>
                                <td class="px-4 py-2 text-right text-danger-700 dark:text-danger-300">
                                    {{ $ladder['unexplained'] === null ? 'cannot compute' : ($ladder['unexplained'] > 0 ? '+' : '') . rtrim(rtrim(number_format($ladder['unexplained'], 2), '0'), '.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    @if ($ladder['unknown_direction']->isNotEmpty())
                        <div class="px-4 py-2 text-xs bg-warning-50 dark:bg-warning-900/30 text-warning-800 dark:text-warning-200">
                            {{ $ladder['unknown_direction']->count() }} movement(s) above stored only a magnitude, with nothing
                            signed persisted to recover the direction from. The unexplained figure could move by that much
                            in either direction — check those entries by hand before drawing a conclusion.
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
