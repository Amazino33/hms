@php
    $shadowReason = \App\Models\Attendance\AttendanceSetting::shadowReason();
    $masterSwitchOff = config('attendance.allow_live_fines') !== true;
@endphp

<x-filament-panels::page>
    @if ($shadowReason)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
            <div class="flex gap-3">
                <x-filament::icon
                    icon="heroicon-o-eye"
                    class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400"
                />
                <div class="text-sm">
                    <p class="font-semibold text-amber-900 dark:text-amber-200">
                        All fines are shadow — nothing is being charged
                    </p>
                    <p class="mt-1 text-amber-800 dark:text-amber-300">
                        {{ $shadowReason }}
                    </p>
                    @if ($masterSwitchOff)
                        <p class="mt-2 text-amber-800 dark:text-amber-300">
                            Live fines stay switched off until leave, swaps and waivers exist. Until then
                            there is no way to excuse a shift somebody was legitimately off for, and a fine
                            with no way to excuse it is just a wrong fine.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    @endif

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
