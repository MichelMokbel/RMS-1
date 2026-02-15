<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Wise Sales Summary</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; margin: 24px; color: #111827; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; font-size: 12px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        .right { text-align: right; }
        .category-row td { background: #e5e7eb; font-weight: 700; font-size: 12px; }
        .subtotal-row td { background: #f3f4f6; font-weight: 600; font-size: 12px; }
        .grand-total-row td { background: #d1d5db; font-weight: 700; font-size: 13px; }
        .item-name { padding-left: 20px !important; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } body { margin: 12px; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.category-sales-summary') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Category Wise Sales Summary'])

    @php
        $grouped = $rows->groupBy(fn ($r) => $r->category_name ?? 'Uncategorized');
        $grandTotalCents = $rows->sum(fn ($r) => (int) ($r->total_cents ?? 0));
        $grandQty = $rows->sum(fn ($r) => (float) ($r->qty_total ?? 0));
    @endphp

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="right">Qty</th>
                <th class="right">Unit Price</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($grouped as $categoryName => $items)
                <tr class="category-row">
                    <td colspan="4">{{ $categoryName }}</td>
                </tr>
                @foreach ($items as $row)
                    <tr>
                        <td class="item-name">{{ $row->item_name ?? '—' }}</td>
                        <td class="right">{{ number_format((float) ($row->qty_total ?? 0), 3) }}</td>
                        <td class="right">{{ $formatCents((int) ($row->avg_unit_price_cents ?? 0)) }}</td>
                        <td class="right">{{ $formatCents((int) ($row->total_cents ?? 0)) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td class="item-name">Category Total</td>
                    <td class="right">{{ number_format($items->sum(fn ($r) => (float) ($r->qty_total ?? 0)), 3) }}</td>
                    <td></td>
                    <td class="right">{{ $formatCents($items->sum(fn ($r) => (int) ($r->total_cents ?? 0))) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No sales found.</td></tr>
            @endforelse
            @if ($grouped->isNotEmpty())
                <tr class="grand-total-row">
                    <td>GRAND TOTAL</td>
                    <td class="right">{{ number_format($grandQty, 3) }}</td>
                    <td></td>
                    <td class="right">{{ $formatCents($grandTotalCents) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
    @include('reports.print-footer')
</body>
</html>
