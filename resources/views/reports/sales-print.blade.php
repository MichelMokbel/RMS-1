<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report</title>
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
        <a class="btn" href="{{ route('reports.sales') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Sales Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>
    <table>
        <thead>
            <tr>
                <th>S.I</th>
                <th>Date & Time</th>
                <th>Branch</th>
                <th>Invoice #</th>
                <th>POS REF</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Payment Type</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sales as $s)
                @php
                    $saleDateTime = $s->issue_date?->copy();
                    if ($saleDateTime && $s->created_at) {
                        $saleDateTime->setTimeFrom($s->created_at);
                    }
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $saleDateTime?->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $branchNames[(int) $s->branch_id] ?? ('Branch '.$s->branch_id) }}</td>
                    <td>{{ $s->invoice_number ?: ('#'.$s->id) }}</td>
                    <td>{{ $s->pos_reference ?? '—' }}</td>
                    <td>{{ $s->customer?->name ?: '—' }}</td>
                    <td>{{ $s->status }}</td>
                    <td>{{ $paymentTypeLabel($s) }}</td>
                    <td class="right">{{ $formatCents($s->total_cents) }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No sales found.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($sales->count() > 0)
        <table>
            <tbody>
                <tr>
                    <td style="border: 0; text-align: right; font-weight: 700;">Total</td>
                    <td class="right" style="font-weight: 700;">{{ $formatCents($sales->sum('total_cents')) }}</td>
                </tr>
            </tbody>
        </table>
    @endif
    @include('reports.print-footer')
</body>
</html>
