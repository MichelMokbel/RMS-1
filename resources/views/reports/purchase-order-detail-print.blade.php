<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order Detail Report</title>
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
        <a class="btn" href="{{ route('reports.purchase-order-detail') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Purchase Order Detail Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>PO #</th>
                <th>Supplier</th>
                <th>Order Date</th>
                <th>Status</th>
                <th>Item</th>
                <th class="right">Qty</th>
                <th class="right">Unit Price</th>
                <th class="right">Total</th>
                <th class="right">Received</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                @php $po = $item->purchaseOrder; @endphp
                <tr>
                    <td>{{ $po?->po_number ?? '—' }}</td>
                    <td>{{ $po?->supplier?->name ?? '—' }}</td>
                    <td>{{ $po?->order_date?->format('Y-m-d') }}</td>
                    <td>{{ $po?->status ?? '—' }}</td>
                    <td>{{ $item->item?->name ?? $item->item_id }}</td>
                    <td class="right">{{ number_format((float) $item->quantity, 3) }}</td>
                    <td class="right">{{ $formatMoney($item->unit_price) }}</td>
                    <td class="right">{{ $formatMoney($item->total_price) }}</td>
                    <td class="right">{{ number_format((float) ($item->received_quantity ?? 0), 3) }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No purchase order items found.</td></tr>
            @endforelse
        </tbody>
    </table>
    @include('reports.print-footer')
</body>
</html>
