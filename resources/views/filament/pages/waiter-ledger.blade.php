<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <button type="button" wire:click="setViewMode('flat')"
            class="px-4 py-2 rounded-lg text-sm font-bold border-2 transition-colors {{ $viewMode === 'flat'
                ? 'border-primary-500 bg-primary-500 text-white'
                : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300' }}">
            Flat List
        </button>
        <button type="button" wire:click="setViewMode('columns')"
            class="px-4 py-2 rounded-lg text-sm font-bold border-2 transition-colors {{ $viewMode === 'columns'
                ? 'border-primary-500 bg-primary-500 text-white'
                : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300' }}">
            Daily Tally (per waiter)
        </button>
    </div>

    @if($viewMode === 'flat')
        {{ $this->table }}
    @else
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex flex-wrap items-end gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Date</label>
                    <input type="date" wire:model.live="tallyDate"
                        class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Destination</label>
                    <select wire:model.live="tallyDestination"
                        class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                        <option value="bar">Bar</option>
                        <option value="kitchen">Kitchen</option>
                        <option value="main">Main</option>
                        <option value="">All</option>
                    </select>
                </div>
            </div>

            @if(count($this->tallyColumns) === 0)
                <div class="text-center text-gray-400 italic py-12">
                    No orders for this date/destination.
                </div>
            @else
                <div class="flex gap-4 overflow-x-auto pb-2">
                    @foreach($this->tallyColumns as $column)
                        <div class="flex-shrink-0 w-56 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="{{ $column['color']['bg'] }} p-3 text-center">
                                <div class="font-black uppercase text-sm {{ $column['color']['text'] }}">
                                    {{ $column['waiter']->name ?? 'Unknown' }}
                                </div>
                                <div class="font-mono font-bold text-lg {{ $column['color']['text'] }}">
                                    ₦{{ number_format($column['total']) }}
                                </div>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-96 overflow-y-auto">
                                @foreach($column['items'] as $item)
                                    <div class="flex justify-between items-center px-3 py-2 text-sm">
                                        <span class="text-gray-700 dark:text-gray-300 truncate pr-2">{{ $item->product_name }}</span>
                                        <span class="font-mono font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                            ₦{{ number_format($item->subtotal) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <span class="font-bold text-gray-600 dark:text-gray-300">Grand Total</span>
                    <span class="font-mono font-black text-xl text-gray-900 dark:text-white">
                        ₦{{ number_format(collect($this->tallyColumns)->sum('total')) }}
                    </span>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
