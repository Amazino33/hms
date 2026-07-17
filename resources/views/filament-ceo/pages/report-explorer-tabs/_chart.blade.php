{{-- Reusable Chart.js canvas via Filament's own bundled chart Alpine
     component — same asset every Filament ChartWidget already loads, no
     new charting dependency. --}}
<div wire:ignore>
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
        data-chart-type="{{ $type }}"
        x-data="chart({
            cachedData: @js($chartData),
            options: @js($options ?? ['plugins' => ['legend' => ['display' => true]]]),
            type: @js($type),
        })"
        class="fi-wi-chart-canvas-ctn"
        style="height: {{ $height ?? 220 }}px"
    >
        <canvas x-ref="canvas"></canvas>
        <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
        <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
        <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
        <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
    </div>
</div>
