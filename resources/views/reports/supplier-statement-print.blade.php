<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Statement</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 13px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        .right { text-align: right; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.supplier-statement') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Supplier Statement'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Supplier: {{ $supplier?->name ?? '—' }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th class="right">Debit</th>
                <th class="right">Credit</th>
                <th class="right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @php $balance = $statement['opening']; @endphp
            <tr>
                <td>Opening</td>
                <td>—</td>
                <td class="right">—</td>
                <td class="right">—</td>
                <td class="right">{{ $formatMoney($balance) }}</td>
            </tr>
            @forelse ($statement['entries'] as $entry)
                @php $balance += (float) $entry['credit'] - (float) $entry['debit']; @endphp
                <tr>
                    <td>{{ $entry['date'] }}</td>
                    <td>{{ $entry['description'] }}</td>
                    <td class="right">{{ $formatMoney($entry['debit']) }}</td>
                    <td class="right">{{ $formatMoney($entry['credit']) }}</td>
                    <td class="right">{{ $formatMoney($balance) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No statement entries found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
