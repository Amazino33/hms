<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Folio Receipt — {{ $snapshot['guest_name'] ?? 'Guest' }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        h2 { font-size: 13px; margin: 0 0 12px 0; color: #6b7280; font-weight: normal; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta-table td { padding: 3px 8px 3px 0; vertical-align: top; }
        .meta-label { color: #6b7280; font-size: 10px; text-transform: uppercase; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { background: #f3f4f6; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #d1d5db; }
        table.items td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        .num { font-family: 'Courier New', monospace; text-align: right; }
        .charge { color: #b91c1c; }
        .credit { color: #15803d; }
        .totals-row td { font-weight: bold; border-top: 2px solid #1f2937; }
        .footer { margin-top: 24px; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
    </style>
</head>
<body>
    <h1>{{ config('app.name', 'HMS') }} — Folio Receipt</h1>
    <h2>Room {{ $snapshot['room_number'] ?? '—' }} · {{ $snapshot['guest_name'] ?? '—' }}</h2>

    <table class="meta-table">
        <tr>
            <td>
                <div class="meta-label">Check-in</div>
                {{ \Carbon\Carbon::parse($snapshot['check_in'])->format('M j, Y') }}
            </td>
            <td>
                <div class="meta-label">Check-out</div>
                {{ \Carbon\Carbon::parse($snapshot['check_out'])->format('M j, Y') }}
            </td>
            <td>
                <div class="meta-label">Receipt generated</div>
                {{ \Carbon\Carbon::parse($snapshot['generated_at'])->format('M j, Y g:ia') }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th>By</th>
                <th style="text-align:right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($snapshot['lines'] as $line)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($line['date'])->format('M j, g:ia') }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $line['type'])) }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td>{{ $line['created_by'] ?? '—' }}</td>
                    <td class="num {{ $line['amount'] >= 0 ? 'charge' : 'credit' }}">
                        {{ $line['amount'] >= 0 ? '+' : '' }}{{ number_format($line['amount'], 2) }}
                    </td>
                </tr>
            @endforeach
            <tr class="totals-row">
                <td colspan="4">Final balance</td>
                <td class="num">₦{{ number_format($snapshot['balance'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Generated from the sealed checkout snapshot — this record cannot be altered.
    </div>
</body>
</html>
