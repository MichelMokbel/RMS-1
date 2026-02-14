<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses Report</title>
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
        <a class="btn" href="{{ route('reports.expenses') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Expenses Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Supplier</th>
                <th>Category</th>
                <th>Status</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($expenses as $e)
                <tr>
                    <td>{{ $e->expense_date?->format('Y-m-d') }}</td>
                    <td>{{ $e->reference ?? '—' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($e->description, 50) }}</td>
                    <td>{{ $e->supplier?->name ?? '—' }}</td>
                    <td>{{ $e->category?->name ?? '—' }}</td>
                    <td>{{ $e->payment_status ?? '—' }}</td>
                    <td style="text-align: right;">{{ number_format((float) $e->total_amount, 3) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No expenses found.</td></tr>
            @endforelse
        </tbody>
        @if ($expenses->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="6">Total</td>
                    <td style="text-align: right;">{{ number_format($expenses->sum('total_amount'), 3) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
