<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Items</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; margin: 14px; color: #000; font-size: 12px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 12px; cursor: pointer; }
        .btn:hover { background: #f3f4f6; }
        @include('reports.print-header-styles')
        .filters { font-size: 11px; color: #374151; margin: 6px 0 10px; }
        .filters span { margin-right: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #cfd3d8; padding: 5px 7px; font-size: 11px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; }
        td.code { font-family: monospace; white-space: nowrap; }
        td.price { text-align: right; white-space: nowrap; }
        td.badge-active { color: #065f46; font-weight: 600; }
        td.badge-inactive { color: #92400e; font-weight: 600; }
        tfoot td { background: #f9fafb; font-weight: 700; }
        @media print { .no-print { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('menu-items.index') }}">Back to Menu Items</a>
    </div>

    @include('reports.print-header', [
        'reportTitle' => 'Menu Items',
        'generatedAt' => $generatedAt,
        'generatedBy' => $generatedBy,
        'warehouse'   => $branchName,
        'salesPerson' => '-',
    ])

    <div class="filters">
        <span><strong>Category:</strong> {{ $categoryName }}</span>
        <span><strong>Status:</strong> {{ $statusLabel }}</span>
        <span><strong>Total:</strong> {{ $items->count() }} item(s)</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Arabic Name</th>
                <th>Category</th>
                <th>Unit</th>
                <th style="text-align:right;">Price</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td class="code">{{ $item->code }}</td>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->arabic_name }}</td>
                    <td>{{ $item->category?->name ?? '—' }}</td>
                    <td>{{ $unitLabel($item->unit) }}</td>
                    <td class="price">{{ number_format((float) $item->selling_price_per_unit, 3) }}</td>
                    <td class="{{ $item->is_active ? 'badge-active' : 'badge-inactive' }}">
                        {{ $item->is_active ? 'Active' : 'Inactive' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No menu items found.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6"><strong>Total Items</strong></td>
                <td><strong>{{ $items->count() }}</strong></td>
            </tr>
        </tfoot>
    </table>

    @include('reports.print-footer')
</body>
</html>
