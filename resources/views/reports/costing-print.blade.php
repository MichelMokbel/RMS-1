<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Costing Report</title>
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
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.costing') }}">Back to Report</a>
    </div>
    <h1>Costing Report</h1>
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Recipe</th>
                <th class="right">Base Cost</th>
                <th class="right">Total Cost</th>
                <th class="right">Cost/Unit</th>
                <th class="right">Selling Price</th>
                <th class="right">Margin %</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($recipes as $recipe)
                @php $c = $costingByRecipe[$recipe->id] ?? null; @endphp
                <tr>
                    <td>{{ $recipe->name }}</td>
                    @if ($c)
                        <td class="right">{{ number_format($c['base_cost_total'], 3) }}</td>
                        <td class="right">{{ number_format($c['total_cost_with_overhead'], 3) }}</td>
                        <td class="right">{{ number_format($c['cost_per_yield_unit_display'], 3) }}</td>
                        <td class="right">{{ $c['selling_price_per_unit'] !== null ? number_format($c['selling_price_per_unit'], 3) : '—' }}</td>
                        <td class="right">{{ $c['margin_pct'] !== null ? number_format($c['margin_pct'] * 100, 1).'%' : '—' }}</td>
                    @else
                        <td colspan="5" class="right">—</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="6">No recipes found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
