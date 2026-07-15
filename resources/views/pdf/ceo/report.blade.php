<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; }
        .header { border-bottom: 2px solid #1D4ED8; padding-bottom: 8px; margin-bottom: 12px; }
        .venue { font-size: 16px; font-weight: bold; color: #1D4ED8; }
        .title { font-size: 14px; font-weight: bold; margin-top: 2px; }
        .meta { font-size: 10px; color: #6b7280; margin-top: 4px; }
        .summary { margin-bottom: 14px; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td { padding: 4px 8px; border: 1px solid #e5e7eb; }
        .summary td.label { font-weight: bold; background: #f9fafb; width: 40%; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data th, table.data td { border: 1px solid #e5e7eb; padding: 4px 6px; font-size: 10px; text-align: left; }
        table.data th { background: #f3f4f6; }
        table.data td.num { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <div class="venue">{{ config('app.name', 'HMS') }}</div>
        <div class="title">{{ $reportTitle }}</div>
        <div class="meta">
            {{ $filtersDescription }}<br>
            Generated {{ $generatedAt->format('M j, Y g:ia') }} by {{ $generatedBy ?? 'System' }}
        </div>
    </div>

    @if(!empty($summary))
        <div class="summary">
            <table>
                @foreach($summary as $label => $value)
                    <tr>
                        <td class="label">{{ $label }}</td>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach($tableHeaders as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($tableRows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td @class(['num' => is_numeric($cell)])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
