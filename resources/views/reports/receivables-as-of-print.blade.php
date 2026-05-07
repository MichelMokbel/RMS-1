<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receivables As Of Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 12px; text-align: left; }
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
        <a class="btn" href="{{ route('reports.receivables-as-of') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Receivables As Of Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>
    <table>
        <thead>
            <tr>
                <th>Customer Code</th>
                <th>Customer</th>
                <th>Invoice #</th>
                <th>Issue Date</th>
                <th>Due Date</th>
                <th class="right">Invoice Total</th>
                <th class="right">Paid As Of</th>
                <th class="right">Balance As Of</th>
                <th>Aging</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['customer_code'] ?: '-' }}</td>
                    <td>{{ $row['customer_name'] }}</td>
                    <td>{{ $row['invoice_number'] }}</td>
                    <td>{{ $row['issue_date'] ?: '-' }}</td>
                    <td>{{ $row['due_date'] ?: '-' }}</td>
                    <td class="right">{{ $formatCents($row['total_cents']) }}</td>
                    <td class="right">{{ $formatCents($row['paid_as_of_cents']) }}</td>
                    <td class="right">{{ $formatCents($row['balance_as_of_cents']) }}</td>
                    <td>{{ $row['aging_label'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No receivables found for this as-of date.</td></tr>
            @endforelse
        </tbody>
        @if ($rows->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="5">Total</td>
                    <td class="right">{{ $formatCents($rows->sum('total_cents')) }}</td>
                    <td class="right">{{ $formatCents($rows->sum('paid_as_of_cents')) }}</td>
                    <td class="right">{{ $formatCents($rows->sum('balance_as_of_cents')) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
    @include('reports.print-footer')
</body>
</html>
