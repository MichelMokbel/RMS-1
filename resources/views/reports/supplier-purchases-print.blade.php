<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Purchases Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 12px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        .right { text-align: right; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="no-print">
    <button class="btn" onclick="window.print()">Print</button>
    <a class="btn" href="{{ route('reports.supplier-purchases') }}">Back to Report</a>
</div>
@include('reports.print-header', ['reportTitle' => 'Supplier Purchases Report'])
<div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>
<table>
    <thead>
    <tr>
        <th>Supplier</th>
        <th>Item Code</th>
        <th>Item</th>
        <th class="right">Ordered Qty</th>
        <th class="right">Received Qty</th>
        <th class="right">Avg Unit Price</th>
        <th class="right">Total Amount</th>
        <th class="right">PO Count</th>
        <th>First Order</th>
        <th>Last Order</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            <td>{{ $row->supplier_name }}</td>
            <td>{{ $row->item_code }}</td>
            <td>{{ $row->item_name }}</td>
            <td class="right">{{ number_format((float) $row->ordered_quantity, 3) }}</td>
            <td class="right">{{ number_format((float) $row->received_quantity, 3) }}</td>
            <td class="right">{{ number_format((float) $row->avg_unit_price, 2) }}</td>
            <td class="right">{{ number_format((float) $row->total_amount, 2) }}</td>
            <td class="right">{{ $row->po_count }}</td>
            <td>{{ $row->first_order_date }}</td>
            <td>{{ $row->last_order_date }}</td>
        </tr>
    @empty
        <tr><td colspan="10">No supplier purchases found.</td></tr>
    @endforelse
    </tbody>
</table>
@include('reports.print-footer')
</body>
</html>
