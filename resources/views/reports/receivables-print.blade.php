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
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Status</th>
                <th>Payment Type</th>
                <th class="right">Total</th>
                <th class="right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $inv)
                <tr>
                    <td>{{ $inv->invoice_number ?: ('#'.$inv->id) }}</td>
                    <td>{{ $inv->customer?->name ?? '—' }}</td>
                    <td>{{ $inv->issue_date?->format('Y-m-d') }}</td>
                    <td>{{ $inv->status }}</td>
                    <td>{{ $inv->payment_type ?? '—' }}</td>
                    <td class="right">{{ $formatCents($inv->total_cents) }}</td>
                    <td class="right">{{ $formatCents($inv->balance_cents) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No invoices found.</td></tr>
            @endforelse
        </tbody>
        @if ($invoices->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="5">Total</td>
                    <td class="right">{{ $formatCents($invoices->sum('total_cents')) }}</td>
                    <td class="right">{{ $formatCents($invoices->sum('balance_cents')) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
    @include('reports.print-footer')
</body>
</html>
