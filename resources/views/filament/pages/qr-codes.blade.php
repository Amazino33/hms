<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Menu-only link: entrance, posters, WhatsApp status. Browse only. --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center gap-4">
            <div class="w-32 h-32 shrink-0 bg-white p-1 rounded">{!! $this->previewSvg($this->menuUrl()) !!}</div>
            <div class="flex-1 min-w-0 text-center sm:text-left">
                <h3 class="font-bold text-gray-900 dark:text-white">Menu-only QR</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">For the entrance, posters and WhatsApp status. Guests can browse the menu but not order.</p>
                <code class="text-xs text-gray-500 dark:text-gray-400 break-all">{{ $this->menuUrl() }}</code>
            </div>
            <div class="flex gap-2 shrink-0">
                <x-filament::button color="gray" wire:click="downloadMenuPng">Download PNG</x-filament::button>
                <x-filament::button wire:click="printMenu">Print A5 poster</x-filament::button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 flex-wrap">
            <x-filament::tabs>
                <x-filament::tabs.item :active="$tab === 'tables'" wire:click="$set('tab', 'tables')">Tables</x-filament::tabs.item>
                <x-filament::tabs.item :active="$tab === 'rooms'" wire:click="$set('tab', 'rooms')">Rooms</x-filament::tabs.item>
            </x-filament::tabs>

            @if ($tab === 'tables')
                <x-filament::button wire:click="printTables">Print all table cards (A6, 4 per page)</x-filament::button>
            @else
                <x-filament::button wire:click="printRooms">Print all room stickers (80 mm, 6 per page)</x-filament::button>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse ($this->places() as $place)
                <div wire:key="qr-{{ $place['type'] }}-{{ $place['id'] }}" class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex items-center gap-4">
                    <div class="w-24 h-24 shrink-0 bg-white p-1 rounded">{!! $this->previewSvg($place['url']) !!}</div>
                    <div class="flex-1 min-w-0 space-y-2">
                        <div class="font-bold text-gray-900 dark:text-white truncate">{{ $place['label'] }}</div>
                        <div class="flex flex-wrap gap-2">
                            <x-filament::button size="sm" color="gray" wire:click="downloadPng('{{ $place['type'] }}', {{ $place['id'] }})">Download PNG</x-filament::button>
                            <x-filament::button size="sm" color="danger" outlined wire:click="openRegenerate('{{ $place['type'] }}', {{ $place['id'] }})">Regenerate</x-filament::button>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nothing here yet.</p>
            @endforelse
        </div>
    </div>

    @if ($regeneratingType)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" wire:click.self="closeRegenerate">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md space-y-4">
                <h3 class="font-bold text-lg text-gray-900 dark:text-white">Replace this QR code?</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    The printed code stops working immediately — anyone scanning it sees "no longer valid". Print the new one before you leave the table or room without a code.
                </p>
                <textarea wire:model="regenerateReason" rows="3" placeholder="Reason (required, e.g. card stolen, photo shared online)"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                <div class="flex justify-end gap-2">
                    <x-filament::button color="gray" wire:click="closeRegenerate">Keep current code</x-filament::button>
                    <x-filament::button color="danger" wire:click="regenerate">Replace QR code</x-filament::button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
