<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit">
                Save as new version
            </x-filament::button>
        </div>
    </form>

    <div class="mt-10">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
            Version history
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every version ever saved. Nothing here is edited or removed — a fine raised
            in March is judged by March's figures, not today's.
        </p>

        <div class="mt-4">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
