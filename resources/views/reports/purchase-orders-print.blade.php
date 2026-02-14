<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 13px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        tfoot td { font-weight: 700; background: #f9fafb; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.purchase-orders') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Purchase Orders Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>PO #</th>
                <th>Supplier</th>
                <th>Date</th>
                <th>Status</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $po)
                <tr>
                    <td>{{ $po->po_number }}</td>
                    <td>{{ $po->supplier?->name ?? '—' }}</td>
                    <td>{{ $po->order_date?->format('Y-m-d') }}</td>
                    <td>{{ $po->status }}</td>
                    <td style="text-align: right;">{{ number_format((float) $po->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No purchase orders found.</td></tr>
            @endforelse
        </tbody>
        @if ($orders->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="4">Total</td>
                    <td style="text-align: right;">{{ number_format($orders->sum('total_amount'), 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
