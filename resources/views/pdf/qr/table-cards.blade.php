{{-- A6 table cards, 4 per A4 (2 × 2), with dashed cut guides. Phase 1B. --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
    .page { position: relative; width: 210mm; height: 297mm; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .card { position: absolute; width: 105mm; height: 148.5mm; box-sizing: border-box; border: 0.3mm dashed #999; text-align: center; background: #fff; }
    .logo { margin-top: 8mm; height: 14mm; }
    .logo img { max-height: 14mm; max-width: 60mm; }
    .venue { margin-top: 8mm; font-size: 12pt; font-weight: bold; height: 14mm; line-height: 14mm; }
    .cta { font-size: 15pt; font-weight: bold; margin-top: 2mm; }
    .qr { margin-top: 3mm; }
    .qr img { width: 60mm; height: 60mm; }
    .label { font-size: 30pt; font-weight: bold; margin-top: 2mm; }
    .note { font-size: 8.5pt; margin-top: 1.5mm; }
</style>
</head>
<body>
@foreach ($pages as $cards)
    <div class="page">
        @foreach ($cards->values() as $i => $card)
            <div class="card" style="left: {{ ($i % 2) * 105 }}mm; top: {{ intdiv($i, 2) * 148.5 }}mm;">
                @if ($logo)
                    <div class="logo"><img src="{{ $logo }}" alt=""></div>
                @else
                    <div class="venue">{{ $venue }}</div>
                @endif
                <div class="cta">Scan to order</div>
                <div class="qr"><img src="{{ $card['qr'] }}" alt=""></div>
                <div class="label">{{ $card['label'] }}</div>
                <div class="note">No app needed — just your phone camera</div>
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
