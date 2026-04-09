<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Inventory List</title>
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
    <a class="btn" href="{{ route('reports.purchase-order-inventory-list') }}">Back to Report</a>
</div>
@include('reports.print-header', ['reportTitle' => 'PO Inventory List'])
<div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>
<table>
    <thead>
    <tr>
        <th>Item Code</th>
        <th>Item</th>
        <th>Unit</th>
        <th class="right">Ordered Quantity</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            <td>{{ $row->item_code !== '' ? $row->item_code : '—' }}</td>
            <td>{{ $row->item_name }}</td>
            <td>{{ $row->unit_of_measure !== '' ? $row->unit_of_measure : '—' }}</td>
            <td class="right">{{ number_format((float) $row->ordered_quantity, 3) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">No purchase-order inventory items found.</td></tr>
    @endforelse
    </tbody>
    @if ($rows->count() > 0)
        <tfoot>
        <tr>
            <th colspan="3" class="right">Total</th>
            <th class="right">{{ number_format((float) $rows->sum('ordered_quantity'), 3) }}</th>
        </tr>
        </tfoot>
    @endif
</table>
@include('reports.print-footer')
</body>
</html>
