<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payables Summary</title>
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
        .cards { display: flex; gap: 12px; margin: 12px 0; }
        .card { border: 1px solid #e5e7eb; padding: 8px 12px; border-radius: 6px; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.payables-summary') }}">Back to Report</a>
    </div>
    <h1>Payables Summary</h1>
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <div class="cards">
        <div class="card">Total: {{ $formatMoney($summary['total']) }}</div>
        <div class="card">Outstanding: {{ $formatMoney($summary['outstanding']) }}</div>
        <div class="card">Overdue: {{ $formatMoney($summary['overdue']) }}</div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th class="right">Count</th>
                <th class="right">Total</th>
                <th class="right">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($summary['by_status'] as $row)
                <tr>
                    <td>{{ $row['status'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right">{{ $formatMoney($row['total']) }}</td>
                    <td class="right">{{ $formatMoney($row['outstanding']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No data found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
