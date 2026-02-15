<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Customers Statement</title>
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
        <a class="btn" href="{{ route('reports.customers-statement') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'All Customers Statement'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th class="right">Opening</th>
                <th class="right">Invoices</th>
                <th class="right">Payments</th>
                <th class="right">Closing</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['customer_name'] }}</td>
                    <td class="right">{{ $formatCents($row['opening']) }}</td>
                    <td class="right">{{ $formatCents($row['invoices']) }}</td>
                    <td class="right">{{ $formatCents($row['payments']) }}</td>
                    <td class="right">{{ $formatCents($row['closing']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No customers found.</td></tr>
            @endforelse
        </tbody>
    </table>
    @include('reports.print-footer')
</body>
</html>
