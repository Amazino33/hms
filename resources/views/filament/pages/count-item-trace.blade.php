<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-xs text-gray-500 mb-1">Section</span>
            <select wire:model.live="section" class="min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm">
                <option value="product">Products</option>
                <option value="ingredient">Ingredients</option>
            </select>
        </label>
        <label class="text-sm flex-1 min-w-[200px]">
            <span class="block text-xs text-gray-500 mb-1">Item</span>
            <select wire:model.live="itemId" class="w-full min-h-[44px] rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm">
                <option value="">Pick an item…</option>
                @foreach($this->itemOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if($itemId)
            <a href="{{ route('count-item-trace.csv', ['section' => $section, 'item' => $itemId]) }}"
                class="min-h-[44px] inline-flex items-center px-4 rounded-lg bg-gray-900 dark:bg-gray-700 text-white text-sm font-bold">Download CSV</a>
        @endif
    </div>

    @if($itemId)
        @include('filament.components.count-item-trace-table', [
            'rows' => $this->rows(),
            'countUrl' => fn ($id) => "/admin/count-session-detail?session_id={$id}",
        ])
    @else
        <p class="text-sm text-gray-500">Pick a product or ingredient to see its line from every count, oldest first.</p>
    @endif
</x-filament-panels::page>
