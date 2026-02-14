<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Report</title>
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
        <a class="btn" href="{{ route('reports.inventory') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Inventory Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Category</th>
                <th class="right">Current Stock</th>
                <th class="right">Min Stock</th>
                <th class="right">Cost</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $i)
                <tr>
                    <td>{{ $i->item_code ?? '—' }}</td>
                    <td>{{ $i->name }}</td>
                    <td>{{ $i->category?->name ?? '—' }}</td>
                    <td class="right">{{ number_format((float) ($i->current_stock ?? 0), 3) }}</td>
                    <td class="right">{{ number_format((float) ($i->minimum_stock ?? 0), 3) }}</td>
                    <td class="right">{{ number_format((float) ($i->cost_per_unit ?? 0), 3) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No inventory items found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
