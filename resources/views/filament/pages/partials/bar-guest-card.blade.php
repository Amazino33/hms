{{-- A guest QR drink card in the bar's ONE queue (Phase 7B, D33): same
     size, timer and Mark Ready button as a normal order card, plus a red
     "GUEST" strip, who accepted it, the ref, and per-line − / ✕. --}}
@php
    $isRoom = $request->isRoom();
    $place = $isRoom ? 'Room '.$request->room?->number : $request->table?->name;
    $by = \App\Services\Guest\GuestShifts::firstName($request->confirmedBy);
    $at = $request->confirmed_at ?? $request->submitted_at;
    $noBartender = count($bartenders) === 0;
@endphp
<div wire:key="guest-card-{{ $request->id }}" class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm hover:shadow-md transition-shadow flex flex-col">
    <div class="flex items-center gap-2 px-4 py-1.5" style="background: rgba(166,25,46,.16); color: #E04355; font-size: 12px; font-weight: 800; letter-spacing: .08em;">
        <x-heroicon-o-device-phone-mobile class="w-4 h-4" />
        <span>GUEST · {{ $place }}</span>
    </div>

    <div class="bg-blue-50 dark:bg-blue-900/20 p-4 border-b border-blue-100 dark:border-blue-900/50 flex justify-between items-center">
        <h3 class="font-black text-lg text-gray-800 dark:text-gray-100">{{ $request->ref }}</h3>
        <span class="text-xs font-bold text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/50 px-2 py-1 rounded-md">
            {{ $at?->diffForHumans(null, true, true) }}
        </span>
    </div>

    <div class="p-4 flex-1 overflow-y-auto max-h-64 space-y-3">
        <div class="text-xs text-gray-500 dark:text-gray-400 font-medium border-b border-gray-100 dark:border-gray-700 pb-2">
            @if ($isRoom)
                Approved by <strong class="text-gray-800 dark:text-gray-100">{{ $by }}</strong> (reception) · Ref {{ $request->ref }}
            @else
                Accepted by <strong class="text-gray-800 dark:text-gray-100">{{ $by }}</strong> · Ref {{ $request->ref }}
            @endif
        </div>

        <div class="space-y-2 pt-1">
            @foreach ($request->items as $line)
                <div wire:key="guest-line-{{ $line->id }}" class="flex items-center gap-2 bg-gray-50 dark:bg-gray-700/30 p-2 rounded-lg border border-gray-100 dark:border-gray-700">
                    <span class="font-black text-lg text-gray-900 dark:text-gray-100 w-10">{{ $line->finalQuantity() }}x</span>
                    <span class="text-gray-700 dark:text-gray-300 font-bold flex-1 leading-tight">
                        {{ $line->name_snapshot }}
                        @if ($line->quantity_final)<span class="text-xs text-gray-500">(asked {{ $line->quantity_requested }})</span>@endif
                        @if ($line->chips || $line->note)
                            <span class="block mt-0.5 font-extrabold text-red-700 dark:text-yellow-300">
                                {{ implode(' · ', $line->chips ?? []) }}
                                @if ($line->note)<span class="block">“{{ $line->note }}”</span>@endif
                            </span>
                        @endif
                    </span>
                    @if ($line->quantity_requested > 1)
                        <button type="button" @click="openEdit({{ $line->id }}, 'reduce', {{ $line->quantity_requested }})" class="w-11 h-11 rounded-lg bg-gray-100 dark:bg-gray-700 font-black text-lg" title="Fewer" aria-label="Give fewer {{ $line->name_snapshot }}">−</button>
                    @endif
                    <button type="button" @click="openEdit({{ $line->id }}, 'remove', 1)" class="w-11 h-11 rounded-lg bg-gray-100 dark:bg-gray-700 font-black text-red-600" title="Remove" aria-label="Remove {{ $line->name_snapshot }}">✕</button>
                </div>
            @endforeach
        </div>
    </div>

    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700 mt-auto">
        <button
            type="button"
            @if (count($bartenders) > 1)
                @click="chooserFor = {{ $request->id }}"
            @else
                wire:click="markGuestReady({{ $request->id }})"
            @endif
            wire:loading.attr="disabled"
            @disabled($noBartender)
            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black text-sm py-3 px-4 rounded-lg shadow-sm transition-all active:scale-95 flex justify-center items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed">
            <x-heroicon-o-check class="w-5 h-5" wire:loading.remove wire:target="markGuestReady({{ $request->id }})"/>
            <x-heroicon-o-arrow-path class="w-5 h-5 animate-spin" wire:loading wire:target="markGuestReady({{ $request->id }})"/>
            <span>MARK READY</span>
        </button>
    </div>
</div>
