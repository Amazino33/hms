@php
    $tiles = $this->getTiles();
    $pending = $this->pendingCount();
@endphp

<x-filament-panels::page>
    <div class="max-w-xs">
        {{ $this->form }}
    </div>

    @if ($pending > 0)
        <div class="rounded-xl border border-gray-300 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
            <span class="font-semibold text-gray-950 dark:text-white">{{ $pending }}</span>
            <span class="text-gray-600 dark:text-gray-400">
                shift(s) on this date have not been judged yet — they are still running, or the
                window has not closed. Nobody is marked absent until it has.
            </span>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
        @foreach ($tiles as $tile)
            <div @class([
                'rounded-xl border p-3',
                'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => $tile['colour'] === 'gray',
                'border-green-200 bg-green-50 dark:border-green-500/30 dark:bg-green-500/10' => $tile['colour'] === 'success',
                'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' => $tile['colour'] === 'warning',
                'border-red-200 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10' => $tile['colour'] === 'danger',
                'border-blue-200 bg-blue-50 dark:border-blue-500/30 dark:bg-blue-500/10' => $tile['colour'] === 'info',
            ])>
                <div class="text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ $tile['value'] }}
                </div>
                <div class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                    {{ $tile['label'] }}
                </div>
            </div>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
