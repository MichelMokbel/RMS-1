<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receivables (AR) Report</title>
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
        .right { text-align: right; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.receivables') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Receivables (AR) Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>
    <table>
        <thead>
            <tr>
                <th>Customer Code</th>
                <th>Customer</th>
                <th class="right">Open Invoices</th>
                <th>Last Invoice Date</th>
                <th class="right">Receivable</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($receivables as $row)
                <tr>
                    <td>{{ $row['customer_code'] ?: '—' }}</td>
                    <td>{{ $row['customer_name'] }}</td>
                    <td class="right">{{ $row['open_invoices'] }}</td>
                    <td>{{ $row['last_invoice_date'] ?: '—' }}</td>
                    <td class="right">{{ $formatCents($row['receivable_cents']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No receivables found.</td></tr>
            @endforelse
        </tbody>
        @if ($receivables->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="4">Total Receivable</td>
                    <td class="right">{{ $formatCents($receivables->sum('receivable_cents')) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
    @include('reports.print-footer')
</body>
</html>
