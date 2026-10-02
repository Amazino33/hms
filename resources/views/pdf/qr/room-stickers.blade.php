{{-- 80 × 80 mm room stickers, 6 per A4 (2 × 3, centred), with dashed cut guides. Phase 1B. --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
    .page { position: relative; width: 210mm; height: 297mm; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .sticker { position: absolute; width: 80mm; height: 80mm; box-sizing: border-box; border: 0.3mm dashed #999; text-align: center; background: #fff; }
    .qr { margin-top: 4mm; }
    .qr img { width: 58mm; height: 58mm; }
    .label { font-size: 12pt; font-weight: bold; margin-top: 2mm; }
</style>
</head>
<body>
@foreach ($pages as $stickers)
    <div class="page">
        @foreach ($stickers->values() as $i => $sticker)
            {{-- 2 columns × 3 rows of 80 mm, gutters of 10 mm, centred on the sheet --}}
            <div class="sticker" style="left: {{ 20 + ($i % 2) * 90 }}mm; top: {{ 18.5 + intdiv($i, 2) * 90 }}mm;">
                <div class="qr"><img src="{{ $sticker['qr'] }}" alt=""></div>
                <div class="label">{{ $sticker['label'] }} · Scan to order</div>
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
