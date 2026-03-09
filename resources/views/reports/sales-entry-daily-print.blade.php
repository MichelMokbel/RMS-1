<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Entry Report Daily</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; margin: 18px; color: #000; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        @include('reports.print-header-styles')
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #cfd3d8; padding: 6px; font-size: 12px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; }
        .right { text-align: right; }
        .center { text-align: center; }
        .totals-row td { font-weight: 700; background: #f9fafb; }
        @media print { .no-print { display: none !important; } body { margin: 12px; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.sales-entry-daily') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Sales Entry Report Daily - Detailed'])
    <table>
        <thead>
            <tr>
                <th class="center">S.I</th>
                <th>Date & Time</th>
                <th>Branch</th>
                <th>Invoice Number</th>
                <th>POS Ref</th>
                <th>Customer</th>
                <th>Sales Person</th>
                <th class="right">Total Trade Revenue</th>
                <th class="right">Discount</th>
                <th class="right">Net Amount</th>
                <th class="right">Cash</th>
                <th class="right">Card</th>
                <th class="right">Credit</th>
                <th class="right">Total Collection</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="center">{{ $row['si'] }}</td>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['branch'] ?? '-' }}</td>
                    <td>{{ $row['invoice_number'] }}</td>
                    <td>{{ $row['pos_ref'] ?? '-' }}</td>
                    <td>{{ $row['customer'] ?? '—' }}</td>
                    <td>{{ $row['sales_person'] ?? '-' }}</td>
                    <td class="right">{{ $formatCents((int) ($row['trade_revenue_cents'] ?? 0)) }}</td>
                    <td class="right">{{ $formatCents((int) ($row['discount_cents'] ?? 0)) }}</td>
                    <td class="right">{{ $formatCents((int) ($row['net_amount_cents'] ?? 0)) }}</td>
                    <td class="right">{{ $formatCents((int) ($row['cash_cents'] ?? 0)) }}</td>
                    <td class="right">{{ $formatCents((int) ($row['card_cents'] ?? 0)) }}</td>
                    <td class="right">{{ $formatCents((int) ($row['credit_cents'] ?? 0)) }}</td>
                    <td class="right">{{ $formatCents((int) ($row['total_collection_cents'] ?? 0)) }}</td>
                </tr>
            @empty
                <tr><td colspan="14">No invoices found.</td></tr>
            @endforelse
            @if (($totals ?? []) !== [])
                <tr class="totals-row">
                    <td colspan="7" class="right">TOTAL</td>
                    <td class="right">{{ $formatCents($totals['trade_revenue_cents'] ?? 0) }}</td>
                    <td class="right">{{ $formatCents($totals['discount_cents'] ?? 0) }}</td>
                    <td class="right">{{ $formatCents($totals['net_amount_cents'] ?? 0) }}</td>
                    <td class="right">{{ $formatCents($totals['cash_cents'] ?? 0) }}</td>
                    <td class="right">{{ $formatCents($totals['card_cents'] ?? 0) }}</td>
                    <td class="right">{{ $formatCents($totals['credit_cents'] ?? 0) }}</td>
                    <td class="right">{{ $formatCents($totals['total_collection_cents'] ?? 0) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
    @include('reports.print-footer')
</body>
</html>
