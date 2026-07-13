<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Add a unit</h3>
            <form wire:submit.prevent="addUnit" class="flex items-end gap-3">
                <div class="flex-1">
                    {{ $this->form }}
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
                    Add
                </button>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Existing units</h3>
            <div class="hms-table-scroll overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->units() as $unit)
                    <div class="flex items-center justify-between py-2" wire:key="unit-{{ $unit->id }}">
                        <span class="text-gray-900 dark:text-white">{{ $unit->name }}</span>
                        <button type="button" wire:click="deleteUnit({{ $unit->id }})"
                            wire:confirm="Remove '{{ $unit->name }}' from the unit list? Products already using it keep it — this only removes it from the picker."
                            class="text-sm text-red-600 hover:text-red-700 font-bold">
                            Remove
                        </button>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400 py-2">No units yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
