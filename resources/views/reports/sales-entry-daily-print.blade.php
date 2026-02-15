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
                <th>Date</th>
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
            @forelse ($invoices as $inv)
                @php
                    $tradeRevenueCents = (int) ($inv->subtotal_cents ?? 0);
                    $discountCents = (int) ($inv->discount_total_cents ?? 0);
                    $netAmountCents = (int) ($inv->total_cents ?? 0);
                    $paymentType = strtolower((string) ($inv->payment_type ?? 'credit'));
                    $cashCents = $paymentType === 'cash' ? $netAmountCents : 0;
                    $cardCents = $paymentType === 'card' ? $netAmountCents : 0;
                    $creditCents = $paymentType === 'credit' ? $netAmountCents : 0;
                    $totalCollectionCents = $cashCents + $cardCents + $creditCents;
                @endphp
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $inv->issue_date?->format('Y-m-d') }}</td>
                    <td>{{ $inv->invoice_number ?: ('#'.$inv->id) }}</td>
                    <td>{{ $inv->pos_reference ?? '-' }}</td>
                    <td>{{ $inv->customer?->name ?? '—' }}</td>
                    <td>{{ $inv->salesPerson?->username ?: ($inv->salesPerson?->name ?? '-') }}</td>
                    <td class="right">{{ $formatCents($tradeRevenueCents) }}</td>
                    <td class="right">{{ $formatCents($discountCents) }}</td>
                    <td class="right">{{ $formatCents($netAmountCents) }}</td>
                    <td class="right">{{ $formatCents($cashCents) }}</td>
                    <td class="right">{{ $formatCents($cardCents) }}</td>
                    <td class="right">{{ $formatCents($creditCents) }}</td>
                    <td class="right">{{ $formatCents($totalCollectionCents) }}</td>
                </tr>
            @empty
                <tr><td colspan="13">No invoices found.</td></tr>
            @endforelse
            @if (($totals ?? []) !== [])
                <tr class="totals-row">
                    <td colspan="6" class="right">TOTAL</td>
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
