<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Aging Summary</title>
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
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.customer-aging-summary') }}">Back to Report</a>
    </div>
    <h1>Customer Aging Summary</h1>
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th class="right">Current</th>
                <th class="right">1-30</th>
                <th class="right">31-60</th>
                <th class="right">61-90</th>
                <th class="right">90+</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['customer_name'] }}</td>
                    <td class="right">{{ $formatCents($row['current']) }}</td>
                    <td class="right">{{ $formatCents($row['bucket_1_30']) }}</td>
                    <td class="right">{{ $formatCents($row['bucket_31_60']) }}</td>
                    <td class="right">{{ $formatCents($row['bucket_61_90']) }}</td>
                    <td class="right">{{ $formatCents($row['bucket_90_plus']) }}</td>
                    <td class="right">{{ $formatCents($row['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No aging data found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
