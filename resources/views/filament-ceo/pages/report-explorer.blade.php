<x-filament-panels::page>
    {{-- Mobile: every table here scrolls horizontally inside its own
         overflow-x-auto wrapper rather than collapsing to stacked cards —
         same choice already made on Sales Report / Daily Digest, kept
         consistent rather than mixing two responsive patterns. --}}

    {{-- ── Range selector (shared across tabs) ────────────────────── --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Date range</label>
            <select wire:model.live="preset" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="this_week">This Week</option>
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="custom">Custom…</option>
            </select>
        </div>
        @if($preset === 'custom')
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">From</label>
                <input type="date" wire:model.live="customFrom" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">To</label>
                <input type="date" wire:model.live="customTo" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
        @endif
        <div class="text-xs text-gray-500">{{ $this->range()->start->format('M j, Y') }} – {{ $this->range()->end->format('M j, Y') }}</div>
        <div class="ml-auto flex gap-2">
            <button type="button" wire:click="exportCsv" class="px-3 py-2 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Export CSV</button>
            <button type="button" wire:click="exportPdf" class="px-3 py-2 text-xs font-semibold rounded-lg bg-primary-600 text-white">Export PDF</button>
        </div>
    </div>

    {{-- ── Tab nav ─────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-1 mt-4 border-b border-gray-200 dark:border-gray-700">
        @foreach(['sales' => 'Sales', 'products' => 'Products', 'debts' => 'Debts', 'expenses' => 'Expenses', 'rooms' => 'Rooms', 'damages' => 'Damages'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                class="px-3 py-2 text-sm font-semibold border-b-2 -mb-px {{ $tab === $key ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @php($data = $this->tabData())

    <div class="mt-4">
        @if($tab === 'sales')
            @include('filament-ceo.pages.report-explorer-tabs.sales', ['data' => $data])
        @elseif($tab === 'products')
            @include('filament-ceo.pages.report-explorer-tabs.products', ['data' => $data])
        @elseif($tab === 'debts')
            @include('filament-ceo.pages.report-explorer-tabs.debts', ['data' => $data])
        @elseif($tab === 'expenses')
            @include('filament-ceo.pages.report-explorer-tabs.expenses', ['data' => $data])
        @elseif($tab === 'rooms')
            @include('filament-ceo.pages.report-explorer-tabs.rooms', ['data' => $data])
        @elseif($tab === 'damages')
            @include('filament-ceo.pages.report-explorer-tabs.damages', ['data' => $data])
        @endif
    </div>
</x-filament-panels::page>
