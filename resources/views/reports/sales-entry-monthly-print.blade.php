<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Entry Report (Monthly)</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; margin: 18px; color: #000; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        @include('reports.print-header-styles')
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #cfd3d8; padding: 7px; font-size: 12px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; }
        .right { text-align: right; }
        @media print { .no-print { display: none !important; } body { margin: 12px; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.sales-entry-monthly') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Sales Entry Report Monthly'])
    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th class="right">Count</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($months as $row)
                <tr>
                    <td>{{ $row['month'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right">{{ $formatCents($row['total_cents']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No sales found.</td></tr>
            @endforelse
        </tbody>
    </table>
    @include('reports.print-footer')
</body>
</html>
