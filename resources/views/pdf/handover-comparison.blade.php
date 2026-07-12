<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Handover Comparison — Session #{{ $session->id }}</title>
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
        .num { font-family: 'Courier New', monospace; }
        .shortage { color: #b91c1c; font-weight: bold; }
        .overage { color: #15803d; }
        .totals-row td { font-weight: bold; border-top: 2px solid #1f2937; }
        .footer { margin-top: 24px; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
        .badge { font-size: 9px; padding: 2px 6px; border-radius: 3px; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-investigation { background: #f3f4f6; color: #4b5563; }
        .badge-debited { background: #fee2e2; color: #991b1b; }
        .badge-written-off { background: #dcfce7; color: #166534; }
    </style>
</head>
<body>
    <h1>{{ config('app.name', 'HMS') }} — Handover Comparison</h1>
    <h2>
        {{ match($session->type) {
            'bar_handover' => 'Bar Handover',
            'kitchen_handover' => 'Kitchen Handover',
            default => ucwords(str_replace('_', ' ', $session->type)),
        } }}
        · {{ $session->warehouse->name ?? '—' }}
        · Session #{{ $session->id }}
        @if ($session->is_closing) · Closing count @endif
        @if ($session->isUnwitnessed()) · Unwitnessed handover @endif
    </h2>

    <table class="meta-table">
        <tr>
            <td>
                <div class="meta-label">Outgoing custodian</div>
                {{ $session->outgoingUser?->name ?? '—' }}
                @if ($session->confirmed_by_outgoing_at)
                    <br><span style="font-size:9px;color:#9ca3af">Confirmed {{ $session->confirmed_by_outgoing_at->format('d M Y H:i') }}</span>
                @endif
            </td>
            <td>
                <div class="meta-label">Incoming custodian</div>
                {{ $session->incomingUser?->name ?? '—' }}
                @if ($session->confirmed_by_incoming_at)
                    <br><span style="font-size:9px;color:#9ca3af">Confirmed {{ $session->confirmed_by_incoming_at->format('d M Y H:i') }}</span>
                @endif
            </td>
            @if ($session->witnessUser)
                <td>
                    <div class="meta-label">Witness</div>
                    {{ $session->witnessUser->name }}
                </td>
            @endif
            <td>
                <div class="meta-label">Sealed</div>
                {{ $session->reviewed_at?->format('d M Y H:i') ?? '—' }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Item</th>
                <th>Expected</th>
                <th>Counted</th>
                <th>Variance</th>
                <th>Unit ₦</th>
                <th>Variance ₦</th>
                <th>Resolution</th>
            </tr>
        </thead>
        <tbody>
            @php
                $isBar = $session->type === 'bar_handover';
                $fmt = fn ($q) => $isBar ? (string) (int) round((float) $q) : rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
                $totalShortage = 0;
            @endphp
            @foreach ($session->items as $item)
                @php $totalShortage += $item->variance < 0 ? abs((float) $item->variance_value) : 0; @endphp
                <tr>
                    <td>{{ $item->itemName() }}</td>
                    <td class="num">{{ $fmt($item->adjusted_expected_quantity) }}</td>
                    <td class="num">{{ $fmt($item->counted_quantity) }}</td>
                    <td class="num {{ $item->variance < 0 ? 'shortage' : ($item->variance > 0 ? 'overage' : '') }}">
                        {{ abs($item->variance) < 0.0001 ? 'None' : $fmt($item->variance) }}
                    </td>
                    <td class="num">{{ $item->unit_selling_price !== null ? number_format((float) $item->unit_selling_price, 2) : '—' }}</td>
                    <td class="num {{ $item->variance < 0 ? 'shortage' : '' }}">
                        {{ $item->variance < 0 && $item->variance_value !== null ? number_format(abs((float) $item->variance_value), 2) : '—' }}
                    </td>
                    <td>
                        @if ($item->discrepancy)
                            <span class="badge badge-{{ str_replace('_', '-', $item->discrepancy->status) }}">
                                {{ match($item->discrepancy->status) {
                                    'pending_resolution' => 'Pending resolution',
                                    'pending_investigation' => 'Pending investigation',
                                    'debited' => 'Debited',
                                    'written_off' => 'Written off',
                                    default => $item->discrepancy->status,
                                } }}
                            </span>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="totals-row">
                <td colspan="5">Total shortage value</td>
                <td class="num shortage">₦{{ number_format($totalShortage, 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Generated {{ now()->format('d M Y H:i') }} from the sealed record for session #{{ $session->id }}.
        Figures are frozen at seal time and do not reflect any subsequent price changes. This is a system-generated report.
    </div>
</body>
</html>
