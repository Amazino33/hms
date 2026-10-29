@props([
    'name',
    'label',
    'hint',
    'cta',
    'types',
    'accept',
    'icon' => 'arrow-up-tray',
    'capture' => null,
    'file' => null,
    'error' => null,
])

{{--
    A big tap-anywhere upload box for the public /join form. The real file
    input sits inside the <label>, visually hidden, so tapping the box opens
    the picker (or the camera on phones). Shows empty / uploading / uploaded /
    error states, with a thumbnail when the upload is an image.
--}}

@php
    $uploaded = $file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

    // Only formats a browser can draw; HEIC and PDF get a document icon instead.
    $previewUrl = $uploaded && in_array($file->guessExtension(), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
        ? $file->temporaryUrl()
        : null;

    $boxState = match (true) {
        filled($error) => 'border-red-400 bg-red-50',
        $uploaded => 'border-green-500 bg-green-50',
        default => 'border-zinc-300 bg-zinc-50 hover:border-zinc-400 hover:bg-zinc-100',
    };
@endphp

<div class="flex flex-col gap-1">
    <span class="text-sm font-medium">{{ $label }}</span>
    <p class="text-xs text-zinc-500">{{ $hint }}</p>

    <label for="{{ $name }}" class="mt-1 flex min-h-24 cursor-pointer items-center rounded-xl border-2 border-dashed p-4 transition focus-within:ring-2 focus-within:ring-zinc-800 focus-within:ring-offset-2 {{ $boxState }}">
        <input id="{{ $name }}" type="file" wire:model="{{ $name }}" accept="{{ $accept }}" @if ($capture) capture="{{ $capture }}" @endif class="sr-only">

        <div wire:loading.flex wire:target="{{ $name }}" class="w-full items-center justify-center gap-2 text-sm font-medium text-zinc-700">
            <flux:icon.loading class="size-5" />
            Uploading…
        </div>

        <div wire:loading.remove wire:target="{{ $name }}" class="flex w-full items-center gap-4">
            @if ($uploaded)
                @if ($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="size-16 shrink-0 rounded-lg object-cover ring-1 ring-black/10">
                @else
                    <div class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-black/10">
                        <flux:icon.document-text class="size-8 text-zinc-500" />
                    </div>
                @endif

                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-1 text-sm font-semibold text-green-700">
                        <flux:icon.check-circle variant="mini" class="size-4" />
                        Uploaded
                    </p>
                    <p class="truncate text-sm text-zinc-700">{{ $file->getClientOriginalName() }}</p>
                    <p class="text-xs font-medium text-zinc-500 underline">Tap to change</p>
                </div>
            @else
                <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-black/10">
                    <flux:icon :icon="$icon" class="size-6 text-zinc-600" />
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-zinc-800">{{ $cta }}</p>
                    <p class="text-xs text-zinc-500">{{ $types }}</p>
                </div>
            @endif
        </div>
    </label>

    @if (filled($error))
        <p class="text-sm text-red-600">{{ $error }}</p>
    @endif
</div>
