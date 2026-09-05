{{--
    Rendered on every admin page (BODY_END render hook) and in the kiosk
    layout. When there is nothing to show it renders one empty div and
    nothing else — no polling, no wire:init roundtrip.

    z-index note: the kiosk layout forces Filament's toast layer (.fi-no)
    to 9999 and its own order modals sit at z-[60]. 9000 therefore puts an
    announcement above anything on the floor screens while still leaving
    notifications visible above it — including the confirmation toast
    fired the moment a notice is signed.
--}}
@php
    $palette = [
        'critical' => [
            'header' => 'bg-red-600',
            'accent' => 'border-red-500',
            'label' => 'Urgent',
            'button' => 'bg-red-600 hover:bg-red-700 focus:ring-red-500',
        ],
        'warning' => [
            'header' => 'bg-amber-500',
            'accent' => 'border-amber-500',
            'label' => 'Important',
            'button' => 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-500',
        ],
        'info' => [
            'header' => 'bg-sky-600',
            'accent' => 'border-sky-500',
            'label' => 'Notice',
            'button' => 'bg-sky-600 hover:bg-sky-700 focus:ring-sky-500',
        ],
    ];

    $bodyProse = '[&_p]:mb-2 [&_ul]:mb-2 [&_ol]:mb-2 [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 [&_a]:underline [&_strong]:font-semibold [&_h2]:text-base [&_h2]:font-bold [&_h2]:mb-1 [&_h3]:font-semibold [&_h3]:mb-1';
@endphp

<div>
    @if ($blocking = $this->blockingNotice)
        @php $tone = $palette[$blocking['severity']] ?? $palette['info']; @endphp

        {{-- No backdrop click-to-close and no × on purpose: the author
             marked this one as needing acknowledgement, so the button is
             the only way past it. --}}
        <div class="fixed inset-0 z-[9000] flex items-center justify-center bg-gray-900/80 p-4 backdrop-blur-sm">
            <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
                <div class="{{ $tone['header'] }} px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-white/80">{{ $tone['label'] }}</p>
                    <h2 class="text-lg font-bold leading-tight text-white">{{ $blocking['title'] }}</h2>
                </div>

                <div class="max-h-[50vh] overflow-y-auto px-5 py-4 text-sm text-gray-700 dark:text-gray-200 {{ $bodyProse }}">
                    {!! \Filament\Forms\Components\RichEditor\RichContentRenderer::make($blocking['body'])->toHtml() !!}
                </div>

                <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-700">
                    <button
                        type="button"
                        wire:click="acknowledge({{ $blocking['id'] }})"
                        wire:loading.attr="disabled"
                        wire:target="acknowledge"
                        class="flex min-h-[3rem] w-full items-center justify-center rounded-xl px-4 py-3 text-base font-bold text-white shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 {{ $tone['button'] }}"
                    >
                        <span wire:loading.remove wire:target="acknowledge">I have read this</span>
                        <span wire:loading wire:target="acknowledge">Saving…</span>
                    </button>

                    {{-- Say plainly that this is recorded. A signature
                         nobody knew they were giving is worth less than one
                         given knowingly. --}}
                    <p class="mt-3 text-center text-xs text-gray-500 dark:text-gray-400">
                        Your name and the time will be recorded against this notice.
                        @if ($blocking['author'])
                            <br>Posted by {{ $blocking['author'] }}@if ($blocking['published_at']) on {{ $blocking['published_at'] }}@endif.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if (count($this->stickyNotices))
        <div class="fixed inset-x-0 bottom-0 z-[9000] flex flex-col gap-3 p-3 sm:inset-x-auto sm:right-4 sm:bottom-4 sm:w-80 sm:p-0">
            @foreach ($this->stickyNotices as $notice)
                @php $tone = $palette[$notice['severity']] ?? $palette['info']; @endphp

                <div
                    wire:key="announcement-{{ $notice['id'] }}"
                    class="rounded-xl border-l-4 bg-white shadow-lg ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 {{ $tone['accent'] }}"
                >
                    <div class="flex items-start justify-between gap-2 px-4 pt-3">
                        <div>
                            <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $tone['label'] }}</p>
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ $notice['title'] }}</h3>
                        </div>

                        {{-- "Later", not "close": it comes back at the next
                             login until it is actually signed. --}}
                        <button
                            type="button"
                            wire:click="dismiss({{ $notice['id'] }})"
                            title="Hide until next login"
                            class="-mr-1 -mt-1 rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="max-h-40 overflow-y-auto px-4 py-2 text-xs text-gray-600 dark:text-gray-300 {{ $bodyProse }}">
                        {!! \Filament\Forms\Components\RichEditor\RichContentRenderer::make($notice['body'])->toHtml() !!}
                    </div>

                    <div class="px-4 pb-3">
                        <button
                            type="button"
                            wire:click="acknowledge({{ $notice['id'] }})"
                            wire:loading.attr="disabled"
                            wire:target="acknowledge"
                            class="flex min-h-[2.5rem] w-full items-center justify-center rounded-lg px-3 py-2 text-sm font-bold text-white shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 {{ $tone['button'] }}"
                        >
                            <span wire:loading.remove wire:target="acknowledge">I have read this</span>
                            <span wire:loading wire:target="acknowledge">Saving…</span>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
