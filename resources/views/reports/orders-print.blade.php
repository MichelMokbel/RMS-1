<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Report</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 24px; }
        h1 { margin: 0 0 8px; font-size: 22px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .toolbar { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; text-decoration: none; font-size: 13px; cursor: pointer; }
        .btn:hover { background: #f3f4f6; }
        .no-print { display: inline-block; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 13px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        tfoot td { font-weight: 700; background: #f9fafb; }
        @media print { .no-print { display: none !important; } body { margin: 12px; } th, td { font-size: 12px; } }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.orders') }}">Back to Report</a>
    </div>
    <h1>Orders Report</h1>
    <div class="meta">
        Generated: {{ $generatedAt->format('Y-m-d H:i') }} |
        Status: {{ $filters['status'] ?? 'all' }} |
        Source: {{ $filters['source'] ?? 'all' }} |
        Branch: {{ $filters['branch_id'] ?? 'all' }} |
        Date from: {{ $filters['date_from'] ?? '—' }} |
        Date to: {{ $filters['date_to'] ?? '—' }} |
        Search: {{ $filters['search'] ?? 'none' }}
    </div>
    <table>
        <thead>
            <tr>
                <th style="width: 110px;">Order #</th>
                <th>Source</th>
                <th style="width: 80px;">Branch</th>
                <th style="width: 100px;">Status</th>
                <th>Customer</th>
                <th style="width: 160px;">Scheduled</th>
                <th style="width: 110px; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>{{ $order->order_number }}</td>
                    <td>{{ $order->source ?? '—' }}</td>
                    <td>{{ $order->branch_id ?? '—' }}</td>
                    <td>{{ $order->status ?? '—' }}</td>
                    <td>{{ $order->customer_name_snapshot ?? '—' }}</td>
                    <td>{{ $order->scheduled_date?->format('Y-m-d') }} {{ $order->scheduled_time }}</td>
                    <td style="text-align: right;">{{ number_format((float) $order->total_amount, 3) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No orders found.</td></tr>
            @endforelse
        </tbody>
        @if ($orders->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="6">Total</td>
                    <td style="text-align: right;">{{ number_format($orders->sum('total_amount'), 3) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
