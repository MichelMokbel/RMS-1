<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order Receiving Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 12px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; font-weight: 600; }
        .right { text-align: right; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="no-print">
    <button class="btn" onclick="window.print()">Print</button>
    <a class="btn" href="{{ route('reports.purchase-order-receiving') }}">Back to Report</a>
</div>
@include('reports.print-header', ['reportTitle' => 'Purchase Order Receiving Report'])
<div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>
<table>
    <thead>
    <tr>
        <th>Received At</th>
        <th>PO #</th>
        <th>Supplier</th>
        <th>Item</th>
        <th class="right">Qty</th>
        <th class="right">Unit Cost</th>
        <th class="right">Total Cost</th>
        <th>Receiver</th>
        <th>Notes</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $row)
        @php
            $receiving = $row->receiving;
            $purchaseOrder = $receiving?->purchaseOrder;
        @endphp
        <tr>
            <td>{{ $receiving?->received_at?->format('Y-m-d H:i') }}</td>
            <td>{{ $purchaseOrder?->po_number ?? '—' }}</td>
            <td>{{ $purchaseOrder?->supplier?->name ?? '—' }}</td>
            <td>{{ $row->item?->item_code }} {{ $row->item?->name }}</td>
            <td class="right">{{ number_format((float) $row->received_quantity, 3) }}</td>
            <td class="right">{{ number_format((float) ($row->unit_cost ?? 0), 4) }}</td>
            <td class="right">{{ number_format((float) ($row->total_cost ?? 0), 4) }}</td>
            <td>{{ $receiving?->creator?->username ?? $receiving?->creator?->email ?? '—' }}</td>
            <td>{{ $receiving?->notes ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="9">No receiving records found.</td></tr>
    @endforelse
    </tbody>
</table>
@include('reports.print-footer')
</body>
</html>
