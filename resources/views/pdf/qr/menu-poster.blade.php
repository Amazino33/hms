{{-- A5 "See our menu" poster for the menu-only link, with a dashed trim guide. Phase 1B. --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
    .poster { position: relative; width: 148mm; height: 210mm; box-sizing: border-box; border: 0.3mm dashed #999; text-align: center; background: #fff; }
    .logo { padding-top: 14mm; height: 22mm; }
    .logo img { max-height: 22mm; max-width: 90mm; }
    .venue { padding-top: 14mm; font-size: 18pt; font-weight: bold; height: 22mm; }
    .cta { font-size: 26pt; font-weight: bold; margin-top: 8mm; }
    .qr { margin-top: 6mm; }
    .qr img { width: 90mm; height: 90mm; }
    .note { font-size: 11pt; margin-top: 4mm; }
</style>
</head>
<body>
@foreach ($pages as $posters)
    @foreach ($posters as $poster)
        <div class="poster">
            @if ($logo)
                <div class="logo"><img src="{{ $logo }}" alt=""></div>
            @else
                <div class="venue">{{ $venue }}</div>
            @endif
            <div class="cta">See our menu</div>
            <div class="qr"><img src="{{ $poster['qr'] }}" alt=""></div>
            <div class="note">Point your phone camera here — no app needed</div>
        </div>
    @endforeach
@endforeach
</body>
</html>
