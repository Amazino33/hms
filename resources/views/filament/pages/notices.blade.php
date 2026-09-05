<x-filament-panels::page>
    @php
        $tones = [
            'critical' => ['label' => 'Urgent', 'chip' => 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'],
            'warning' => ['label' => 'Important', 'chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'],
            'info' => ['label' => 'Notice', 'chip' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300'],
        ];
        $bodyProse = '[&_p]:mb-2 [&_ul]:mb-2 [&_ol]:mb-2 [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 [&_a]:underline [&_strong]:font-semibold [&_h2]:text-base [&_h2]:font-bold [&_h3]:font-semibold';
    @endphp

    @if ($notices->isEmpty())
        <div class="text-sm text-gray-500 dark:text-gray-400">
            You have not been sent any announcements yet.
        </div>
    @endif

    @foreach ($notices as $notice)
        @php
            $tone = $tones[$notice->severity] ?? $tones['info'];
            $signed = $notice->acknowledgements->first();
            $isPending = in_array($notice->id, $pendingIds, true);
        @endphp

        <div class="fi-section space-y-3 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <span class="inline-block rounded-md px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide {{ $tone['chip'] }}">
                        {{ $tone['label'] }}
                    </span>
                    <div class="mt-1 text-lg font-semibold">{{ $notice->title }}</div>
                </div>

                <div class="text-right text-xs text-gray-500 dark:text-gray-400">
                    @if ($notice->published_at)
                        <div>Posted {{ $notice->published_at->format('M j, Y g:ia') }}</div>
                    @endif
                    @if ($notice->creator)
                        <div>by {{ $notice->creator->name }}</div>
                    @endif
                    @if (! $notice->isLive())
                        <div class="mt-1 italic">No longer showing</div>
                    @endif
                </div>
            </div>

            <div class="text-sm text-gray-700 dark:text-gray-200 {{ $bodyProse }}">
                {!! \Filament\Forms\Components\RichEditor\RichContentRenderer::make($notice->body)->toHtml() !!}
            </div>

            <div class="border-t border-gray-100 pt-3 dark:border-gray-800">
                @if ($signed)
                    <div class="text-xs font-medium text-green-700 dark:text-green-400">
                        You marked this as read on {{ $signed->acknowledged_at->format('M j, Y \a\t g:ia') }}
                        ({{ $signed->context === 'kiosk' ? 'from the kiosk' : 'from the admin panel' }}).
                    </div>
                @elseif ($isPending)
                    <button
                        type="button"
                        wire:click="acknowledge({{ $notice->id }})"
                        wire:loading.attr="disabled"
                        wire:target="acknowledge"
                        class="inline-flex min-h-[2.5rem] items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition-colors hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="acknowledge">I have read this</span>
                        <span wire:loading wire:target="acknowledge">Saving…</span>
                    </button>
                @else
                    {{-- Withdrawn or expired before they got to it. Saying so
                         is more honest than showing a button that would be
                         refused, or silently showing nothing at all. --}}
                    <div class="text-xs italic text-gray-500 dark:text-gray-400">
                        You did not mark this as read while it was showing.
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</x-filament-panels::page>
