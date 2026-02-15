<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payables (AP) Report</title>
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
        .aging { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
        .aging-box { border: 1px solid #e5e7eb; padding: 12px; min-width: 100px; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.payables') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Payables (AP) Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Tab: {{ $tab }} | Filters: {{ json_encode($filters) }}</div>

    @if ($tab === 'aging')
        <h2>Aging Summary</h2>
        <div class="aging">
            <div class="aging-box"><strong>Current</strong><br>{{ $formatMoney($aging['current'] ?? 0) }}</div>
            <div class="aging-box"><strong>1-30 days</strong><br>{{ $formatMoney($aging['1_30'] ?? 0) }}</div>
            <div class="aging-box"><strong>31-60 days</strong><br>{{ $formatMoney($aging['31_60'] ?? 0) }}</div>
            <div class="aging-box"><strong>61-90 days</strong><br>{{ $formatMoney($aging['61_90'] ?? 0) }}</div>
            <div class="aging-box"><strong>90+ days</strong><br>{{ $formatMoney($aging['90_plus'] ?? 0) }}</div>
        </div>
    @endif

    @if ($tab === 'invoices')
        <h2>Invoice Register</h2>
        <table>
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th>Due</th>
                    <th>Status</th>
                    <th class="right">Total</th>
                    <th class="right">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoicePage->getCollection() as $inv)
                    @php $outstanding = max((float) $inv->total_amount - (float) ($inv->paid_sum ?? 0), 0); @endphp
                    <tr>
                        <td>{{ $inv->invoice_number }}</td>
                        <td>{{ $inv->supplier?->name ?? '—' }}</td>
                        <td>{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                        <td>{{ $inv->due_date?->format('Y-m-d') }}</td>
                        <td>{{ $inv->status }}</td>
                        <td class="right">{{ $formatMoney($inv->total_amount) }}</td>
                        <td class="right">{{ $formatMoney($outstanding) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($tab === 'payments')
        <h2>Payment Register</h2>
        <table>
            <thead>
                <tr>
                    <th>Payment #</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th>Method</th>
                    <th class="right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($paymentPage->getCollection() as $pay)
                    <tr>
                        <td>{{ $pay->id }}</td>
                        <td>{{ $pay->supplier?->name ?? '—' }}</td>
                        <td>{{ $pay->payment_date?->format('Y-m-d') }}</td>
                        <td>{{ $pay->payment_method ?? '—' }}</td>
                        <td class="right">{{ $formatMoney($pay->amount ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @include('reports.print-footer')
</body>
</html>
