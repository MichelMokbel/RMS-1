<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Transactions Report</title>
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
    <a class="btn" href="{{ route('reports.inventory-transactions') }}">Back to Report</a>
</div>
@include('reports.print-header', ['reportTitle' => 'Inventory Transactions Report'])
<div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>
<table>
    <thead>
    <tr>
        <th>Date</th>
        <th>Item</th>
        <th>Category</th>
        <th>Type</th>
        <th class="right">Qty</th>
        <th class="right">Unit Cost</th>
        <th class="right">Total Cost</th>
        <th>Reference</th>
        <th>User</th>
        <th>Notes</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($transactions as $transaction)
        <tr>
            <td>{{ $transaction->transaction_date?->format('Y-m-d H:i') }}</td>
            <td>{{ $transaction->item?->item_code }} {{ $transaction->item?->name }}</td>
            <td>{{ $transaction->item?->categoryLabel() ?? '—' }}</td>
            <td>{{ ucfirst($transaction->transaction_type) }}</td>
            <td class="right">{{ number_format((float) $transaction->delta(), 3) }}</td>
            <td class="right">{{ number_format((float) ($transaction->unit_cost ?? 0), 4) }}</td>
            <td class="right">{{ number_format((float) ($transaction->total_cost ?? 0), 4) }}</td>
            <td>{{ trim(($transaction->reference_type ?? '').' '.($transaction->reference_id ?? '')) }}</td>
            <td>{{ $transaction->user?->username ?? $transaction->user?->email ?? '—' }}</td>
            <td>{{ $transaction->notes ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="10">No inventory transactions found.</td></tr>
    @endforelse
    </tbody>
</table>
@include('reports.print-footer')
</body>
</html>
