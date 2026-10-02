{{-- Guest QR extras on the Bar Display (Phase 7B, D33): sound bar (D16),
     bartender-shift banners (D1, §10), "Returned from rooms" (D26), and the
     "Who is marking this ready?" / reduce-remove sheets. The guest cards
     themselves sit in the main queue (partials/bar-guest-card). --}}
@include('partials.guest-chime')

@php $guestIds = $queue->where('type', 'guest')->map(fn ($e) => $e['request']->id)->values()->all(); @endphp
{{-- Re-created whenever the set of guest cards changes, so a NEW one chimes. --}}
<span wire:key="guest-bar-ids-{{ md5(json_encode($guestIds)) }}" x-init="sync(@js($guestIds))" hidden></span>

<div class="space-y-3 mb-4">
    <div class="flex items-center justify-end gap-2">
        <span x-show="!sound" x-cloak class="text-xs font-bold bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-full px-3 py-1">🔇 Sound off</span>
    </div>

    <button type="button" x-show="!sound" x-cloak @click="enableSound()"
        class="w-full h-11 rounded-lg bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-gray-100 font-semibold text-sm">
        🔔 Tap to enable order sounds
    </button>

    @if (count($bartenders) === 0 && $guestWaiting > 0)
        <div class="rounded-lg bg-red-600 text-white font-bold p-3 text-sm">
            {{ $guestWaiting }} guest {{ $guestWaiting === 1 ? 'order' : 'orders' }} waiting — start your shift to mark {{ $guestWaiting === 1 ? 'it' : 'them' }} ready.
        </div>
    @elseif (count($bartenders) > 1)
        <div class="rounded-lg bg-amber-400 text-black font-bold p-3 text-sm">
            {{ count($bartenders) }} bartender shifts open — end the old one.
        </div>
    @endif

    {{-- D26: refused room drinks come back to the bar --}}
    @if ($returns->isNotEmpty())
        <div class="space-y-2">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Returned from rooms</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach ($returns as $refusal)
                    <div wire:key="guest-return-{{ $refusal->id }}" class="rounded-xl border-2 border-teal-500 bg-white dark:bg-gray-800 p-3">
                        <div class="font-bold text-gray-900 dark:text-white">Room {{ $refusal->request?->room?->number }} · {{ $refusal->request?->ref }}</div>
                        <ul class="text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($refusal->order?->items ?? [] as $item)
                                <li>{{ $item->quantity }}× {{ $item->product_name }}</li>
                            @endforeach
                        </ul>
                        <div class="text-xs text-gray-500">Refused: {{ $refusal->reason }}</div>
                        <button type="button" wire:click="confirmReturn({{ $refusal->id }})" class="mt-2 w-full min-h-[52px] rounded-xl bg-teal-600 text-white font-bold">Returned ✓</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

{{-- D1: "Who is marking this ready?" when more than one bartender shift is open --}}
<div x-show="chooserFor !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="chooserFor = null">
    <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3" role="dialog" aria-modal="true">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Who is marking this ready?</h3>
        @foreach ($bartenders as $bartender)
            <button type="button" @click="$wire.markGuestReady(chooserFor, {{ $bartender['id'] }}); chooserFor = null"
                class="w-full min-h-[56px] rounded-xl bg-blue-600 text-white font-bold text-lg">{{ $bartender['name'] }}</button>
        @endforeach
        <button type="button" @click="chooserFor = null" class="w-full h-11 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold text-gray-700 dark:text-gray-300">Cancel</button>
    </div>
</div>

{{-- Reduce / remove reason sheet — the guest sees the reason --}}
<div x-show="edit !== null" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="edit = null">
    <div class="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-sm space-y-3" role="dialog" aria-modal="true">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="mode === 'reduce' ? 'Give fewer' : 'Remove this drink'"></h3>
        <template x-if="mode === 'reduce'">
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">New quantity
                <input type="number" min="1" x-model.number="qty" class="mt-1 w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
            </label>
        </template>
        <div class="grid grid-cols-3 gap-2">
            @foreach ($presets as $preset)
                <button type="button" @click="reason = '{{ $preset }}'" :class="reason === '{{ $preset }}' ? 'bg-amber-400 text-black' : 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200'"
                    class="min-h-[48px] rounded-lg font-semibold text-sm">{{ $preset }}</button>
            @endforeach
        </div>
        <input type="text" x-show="reason === 'Other'" x-model="other" maxlength="100" placeholder="What happened?"
            class="w-full h-11 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3">
        <div class="flex gap-2">
            <button type="button" @click="edit = null" class="flex-1 h-12 rounded-xl border border-gray-300 dark:border-gray-600 font-semibold text-gray-700 dark:text-gray-300">Back</button>
            <button type="button" @click="submitEdit()" class="flex-1 h-12 rounded-xl bg-red-600 text-white font-bold">Confirm</button>
        </div>
    </div>
</div>
