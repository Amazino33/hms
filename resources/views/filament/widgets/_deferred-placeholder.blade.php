<div wire:init="load" class="filament-widget-deferred">
    <div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 shadow-sm animate-pulse">
        {{-- Header row --}}
        <div class="flex items-center justify-between mb-4">
            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-full w-2/5"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded-full w-14"></div>
        </div>
        {{-- Three stat cards --}}
        <div class="grid grid-cols-3 gap-3">
            @for ($i = 0; $i < 3; $i++)
            <div class="rounded-xl bg-gray-100 dark:bg-gray-700 p-4 space-y-2">
                <div class="h-3 bg-gray-200 dark:bg-gray-600 rounded-full w-3/4"></div>
                <div class="h-6 bg-gray-300 dark:bg-gray-500 rounded-full w-1/2"></div>
                <div class="h-2 bg-gray-200 dark:bg-gray-600 rounded-full w-full"></div>
            </div>
            @endfor
        </div>
    </div>
</div>