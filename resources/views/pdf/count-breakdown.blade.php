@php
    $q = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $naira = fn ($n) => $n === null ? '—' : '₦'.number_format(abs((float) $n), 2);
    $signed = fn ($n) => (float) $n < 0 ? '−'.$q(abs($n)) : ((float) $n > 0 ? '+'.$q($n) : '0');
    $signedNaira = fn ($n) => $n === null ? '—' : ((float) $n < 0 ? '−' : ((float) $n > 0 ? '+' : '')).$naira($n);
    $tone = fn ($n) => (float) $n < 0 ? 'short' : ((float) $n > 0 ? 'over' : 'exact');
    $audit = $d['audit'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Count #{{ $d['session_id'] }}</title>
    <style>
        @page { margin: 22px 24px; }
        /* DejaVu Sans ships with dompdf and, unlike Helvetica, has the naira sign. */
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111827; }
        h1 { font-size: 16px; margin: 0 0 2px 0; }
        h2 { font-size: 12px; margin: 14px 0 6px 0; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f3f4f6; text-align: right; padding: 4px 5px; font-size: 8px; text-transform: uppercase; border-bottom: 1px solid #d1d5db; }
        th.l, td.l { text-align: left; }
        td { padding: 4px 5px; border-bottom: 1px solid #e5e7eb; text-align: right; }
        .short { color: #b91c1c; font-weight: bold; }
        .over { color: #b45309; font-weight: bold; }
        .exact { color: #9ca3af; }
        tfoot td { font-weight: bold; border-top: 2px solid #111827; background: #f9fafb; }
        .meta td { text-align: left; border: none; padding: 2px 10px 2px 0; }
        .note { margin: 0 0 6px 0; }
        .footer { margin-top: 18px; font-size: 8px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    <h1>{{ config('app.name', 'HMS') }} — {{ $audit ? 'Count Breakdown' : 'Count Summary' }}</h1>
    <div class="muted">{{ $d['title'] }} · {{ $d['warehouse'] ?? '—' }} · Count #{{ $d['session_id'] }}</div>

    @if(! $d['recorded'])
        <p>Breakdown not recorded (before this update).</p>
    @else
        <table class="meta" style="margin-top: 8px;">
            <tr>
                <td><span class="muted">Sealed</span> {{ $d['sealed_at'] ?? '—' }}</td>
                <td><span class="muted">Covers</span> {{ $d['window_from'] ?? 'start' }} → {{ $d['window_to'] }}</td>
                <td><span class="muted">Counted by</span> {{ $d['counted_by'] ?? '—' }}</td>
                @foreach($d['signers'] as $signer)
                    <td><span class="muted">{{ $signer['label'] }}</span> {{ $signer['name'] }}</td>
                @endforeach
            </tr>
        </table>

        @if(! empty($d['reconstructed_at']))
            <p style="color:#92400e;">Rebuilt from records on {{ $d['reconstructed_at'] }}: this count was sealed before breakdowns were saved at the seal. Open orders at handover were not recorded.</p>
        @endif

        @if(count($d['open_orders']))
            <h2>Open at handover ({{ count($d['open_orders']) }})</h2>
            <table>
                <thead><tr><th class="l">Order</th><th class="l">Item</th><th>Qty</th><th class="l">Waiter</th><th class="l">Placed</th></tr></thead>
                <tbody>
                    @foreach($d['open_orders'] as $o)
                        <tr><td class="l">{{ $o['order'] }}</td><td class="l">{{ $o['item'] }}</td><td>{{ $q($o['quantity']) }}</td><td class="l">{{ $o['waiter'] ?? '—' }}</td><td class="l">{{ $o['placed_at'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @foreach($d['sections'] as $section)
            <h2>{{ $section['label'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th class="l">Item</th><th>B/F</th><th>Transferred</th><th>Returns</th><th>Other in</th><th>Available</th>
                        <th>{{ $section['key'] === 'ingredient' ? 'Used' : 'Sold' }}</th><th>Sales ₦</th><th>Damages</th><th>Other out</th><th>Unrecorded</th>
                        <th>Expected</th><th>Counted</th><th>Variance</th><th>Variance ₦ (sell)</th>
                        @if($audit)<th>Variance ₦ (cost)</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach(collect($section['lines'])->sortBy([fn ($a, $b) => (abs($b['variance']) > 0) <=> (abs($a['variance']) > 0), fn ($a, $b) => strcmp($a['name'], $b['name'])]) as $l)
                        <tr>
                            <td class="l">{{ $l['name'] }}@if($audit && ! empty($l['repeat'])) <span class="short">(short {{ $l['repeat']['short'] }} of last {{ $l['repeat']['of'] }})</span>@endif</td>
                            <td>{{ $q($l['brought_forward']) }}</td><td>{{ $q($l['transferred']) }}</td><td>{{ $q($l['returns']) }}</td><td>{{ $q($l['other_in']) }}</td>
                            <td>{{ $q($l['available']) }}</td><td>{{ $q($l['sold']) }}</td><td>{{ $l['sales_amount'] === null ? '—' : $naira($l['sales_amount']) }}</td>
                            <td>{{ $q($l['damages']) }}</td><td>{{ $q($l['other_out']) }}</td><td>{{ $l['unrecorded'] ? $signed($l['unrecorded']) : '0' }}</td>
                            <td>{{ $q($l['expected']) }}</td><td>{{ $q($l['counted']) }}</td>
                            <td class="{{ $tone($l['variance']) }}">{{ $signed($l['variance']) }}</td>
                            <td class="{{ $tone($l['variance']) }}">{{ abs($l['variance']) > 0 ? $signedNaira($l['value']) : '—' }}</td>
                            @if($audit)<td class="{{ $tone($l['variance']) }}">{{ abs($l['variance']) > 0 ? $signedNaira($l['value_cost']) : '—' }}</td>@endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td class="l">Totals ({{ $section['totals']['variance_items'] }} with variance)</td>
                        <td colspan="6"></td><td>{{ $naira($section['totals']['sales']) }}</td><td colspan="6"></td>
                        <td class="{{ $tone($section['totals']['variance_value']) }}">{{ $signedNaira($section['totals']['variance_value']) }}</td>
                        @if($audit)<td class="{{ $tone($section['totals']['variance_value_cost']) }}">{{ $signedNaira($section['totals']['variance_value_cost']) }}</td>@endif
                    </tr>
                </tfoot>
            </table>

            @php($noted = collect($section['lines'])->filter(fn ($l) => count($l['notes'])))
            @if($noted->isNotEmpty())
                <h2>Variance explanations — {{ $section['label'] }}</h2>
                @foreach($noted as $l)
                    <div class="note"><strong>{{ $l['name'] }}</strong> ({{ $signed($l['variance']) }})</div>
                    @foreach($l['notes'] as $n)
                        <div class="note">“{{ $n['body'] }}” <span class="muted">— {{ $n['author'] }}, {{ $n['at'] }}</span></div>
                    @endforeach
                @endforeach
            @endif
        @endforeach
    @endif

    <div class="footer">Generated {{ now()->venueTime()->format('M j, Y g:i A') }} from the figures frozen when this count was sealed.</div>
</body>
</html>
