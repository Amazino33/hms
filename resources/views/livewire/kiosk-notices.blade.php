<?php

use App\Services\AnnouncementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The notice archive for floor staff.
 *
 * Read-only on purpose. Anything still outstanding is already in front of
 * them via the announcement board mounted in the kiosk layout — this
 * screen exists so a waiter can go back and re-read a notice they signed
 * last week without being sent to the admin panel to do it.
 */
new class extends Component
{
    public function with(): array
    {
        $user = Auth::guard('staff_pin')->user();

        return [
            'staff' => $user,
            'notices' => $user
                ? app(AnnouncementService::class)->historyFor($user)
                : collect(),
        ];
    }
}; ?>

<div class="min-h-screen bg-gray-900 p-4 text-gray-100">
    @php
        $tones = [
            'critical' => ['label' => 'Urgent', 'chip' => 'bg-red-500/20 text-red-300', 'accent' => 'border-red-500'],
            'warning' => ['label' => 'Important', 'chip' => 'bg-amber-500/20 text-amber-300', 'accent' => 'border-amber-500'],
            'info' => ['label' => 'Notice', 'chip' => 'bg-sky-500/20 text-sky-300', 'accent' => 'border-sky-500'],
        ];
        $bodyProse = '[&_p]:mb-2 [&_ul]:mb-2 [&_ol]:mb-2 [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 [&_a]:underline [&_strong]:font-semibold';
    @endphp

    <div class="mx-auto max-w-2xl">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold">Notices</h1>
                @if ($staff)
                    <p class="text-xs text-gray-400">{{ $staff->name }}</p>
                @endif
            </div>

            <a href="{{ session('kiosk_device_id') ? route('kiosk.home') : route('staff.home') }}"
               class="rounded-lg bg-gray-700 px-4 py-2.5 text-sm font-bold text-white touch-manipulation">
                ← Back
            </a>
        </div>

        @if ($notices->isEmpty())
            <div class="rounded-xl bg-gray-800 p-6 text-center text-sm text-gray-400">
                You have not been sent any notices yet.
            </div>
        @endif

        @foreach ($notices as $notice)
            @php
                $tone = $tones[$notice->severity] ?? $tones['info'];
                $signed = $notice->acknowledgements->first();
            @endphp

            <div class="mb-3 rounded-xl border-l-4 bg-gray-800 p-4 {{ $tone['accent'] }}">
                <span class="inline-block rounded px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide {{ $tone['chip'] }}">
                    {{ $tone['label'] }}
                </span>

                <h2 class="mt-1 text-base font-bold text-white">{{ $notice->title }}</h2>

                <div class="mt-2 text-sm text-gray-300 {{ $bodyProse }}">
                    {!! \Filament\Forms\Components\RichEditor\RichContentRenderer::make($notice->body)->toHtml() !!}
                </div>

                <div class="mt-3 border-t border-gray-700 pt-2 text-xs">
                    @if ($signed)
                        <span class="font-medium text-green-400">
                            You marked this as read on {{ $signed->acknowledged_at->format('M j, Y \a\t g:ia') }}.
                        </span>
                    @else
                        <span class="italic text-gray-400">Not marked as read.</span>
                    @endif

                    @if ($notice->published_at)
                        <span class="ml-2 text-gray-500">Posted {{ $notice->published_at->format('M j, Y') }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
